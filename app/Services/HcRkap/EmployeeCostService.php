<?php

namespace App\Services\HcRkap;

use App\Models\HcRkap\BudgetEntry;
use App\Models\HcRkap\CostType;
use App\Models\HcRkap\Employee;
use App\Models\HcRkap\FiscalYear;
use Illuminate\Support\Facades\DB;

/**
 * Menghitung estimasi biaya tahunan per pegawai berdasarkan asumsi tahun anggaran.
 *
 * Komposisi biaya pegawai honor/outsource mengikuti struktur workbook sheet "(2)":
 * Gaji, BPJS TK, BPJS Kesehatan, Bonus, THR, Kompensasi, Management Fee, dan PPN.
 */
class EmployeeCostService
{
    /** Kode asumsi kenaikan gaji per status pegawai. */
    public const KENAIKAN_CODES = [
        'tetap' => 'kenaikan_tetap',
        'kontrak' => 'kenaikan_kontrak',
        'honor' => 'kenaikan_ump',
        'direksi' => 'kenaikan_dirkom',
    ];

    private array $assumptions = [];

    public function forYear(FiscalYear $year): array
    {
        $this->assumptions = $year->assumptions()->pluck('value', 'code')->all();

        $statuses = ['tetap', 'kontrak', 'honor', 'direksi'];
        $out = [];

        foreach ($statuses as $status) {
            $employees = Employee::with(['workUnit', 'salaryGrade'])
                ->where('status', $status)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            if ($employees->isEmpty() && $status === 'direksi') {
                continue; // status tanpa data tidak perlu tab kosong
            }

            $rows = $employees->map(fn ($e) => $this->costRow($e))->values();

            $componentKeys = $rows->first()['components'] ?? [];
            $totals = [];
            foreach (array_keys($componentKeys) as $key) {
                $totals[$key] = (float) $rows->sum(fn ($r) => $r['components'][$key]);
            }

            $out[$status] = [
                'headcount' => $rows->count(),
                'employees' => $rows,
                'totals' => $totals,
                'grand_total' => (float) $rows->sum('total'),
                'assumption_notes' => $this->notesFor($status),
            ];
        }

        return $out;
    }

    /**
     * Jumlah field pegawai (mis. base_salary) per unit kerja — nilai bulanan.
     *
     * @return array<int, float>  [work_unit_id => total bulanan]
     */
    public function sumByUnit(string $field): array
    {
        return Employee::query()
            ->where('is_active', true)
            ->whereNotNull('work_unit_id')
            ->groupBy('work_unit_id')
            ->selectRaw("work_unit_id, SUM({$field}) as total")
            ->pluck('total', 'work_unit_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Sinkronkan seluruh jenis biaya "employee_source" ke entri RKAP tahun ini:
     * nilai = grand total field pegawai per unit, disebar rata 12 bulan.
     * Tahun final dilewati (terkunci).
     */
    public function syncEmployeeSourcedEntries(FiscalYear $year): void
    {
        if ($year->status === 'final') {
            return;
        }

        $sourced = CostType::whereNotNull('employee_source')->get();
        if ($sourced->isEmpty()) {
            return;
        }

        $columnSources = ['base_salary', 'position_allowance', 'transport_allowance'];
        $componentSources = ['thr', 'bonus', 'kompensasi', 'pph21', 'bpjs_kes', 'bpjs_tk', 'thp'];
        $allowed = [...$columnSources, 'dplk', 'model', ...$componentSources];

        DB::transaction(function () use ($sourced, $year, $allowed, $columnSources) {
            $now = now();
            foreach ($sourced as $type) {
                // format sumber: "key", "key:status" (mis. "kompensasi:honor"),
                // atau "model:<nama>" (purnabakti / cuti / pph — nilai per bulan)
                [$sourceKey, $sourceStatus] = array_pad(explode(':', (string) $type->employee_source, 2), 2, null);
                if (! in_array($sourceKey, $allowed, true)) {
                    continue;
                }

                // ganti penuh: hapus entri lama komponen ini lalu tulis dari data pegawai
                BudgetEntry::where('fiscal_year_id', $year->id)
                    ->where('cost_type_id', $type->id)
                    ->where('scenario', 'rkap')
                    ->delete();

                $byUnit = match (true) {
                    $sourceKey === 'model' => $this->modelByUnit($year, (string) $sourceStatus),
                    $sourceKey === 'dplk' => $this->dplkByUnit($year),
                    in_array($sourceKey, $columnSources, true) => $this->sumByUnit($sourceKey),
                    default => $this->componentByUnit($year, $sourceKey, $sourceStatus),
                };

                $rows = [];
                foreach ($byUnit as $unitId => $monthly) {
                    for ($m = 1; $m <= 12; $m++) {
                        // model menghasilkan nilai per bulan; sumber lain nilai rata
                        $amount = is_array($monthly) ? round((float) ($monthly[$m] ?? 0), 2) : $monthly;
                        if ($amount == 0.0) {
                            continue;
                        }
                        $rows[] = [
                            'fiscal_year_id' => $year->id,
                            'cost_type_id' => $type->id,
                            'work_unit_id' => $unitId,
                            'month' => $m,
                            'scenario' => 'rkap',
                            'amount' => $amount,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    BudgetEntry::insert($chunk);
                }
            }
        });
    }

    /** Cache matriks [unit][status][komponen] per tahun agar sync tidak menghitung ulang. */
    private array $componentMatrixCache = [];

    /**
     * Grand total satu komponen biaya (mis. thr, bonus, pph21, thp) per unit
     * kerja — nilai bulanan (tahunan ÷ 12), opsional dibatasi satu status
     * pegawai. Inilah tautan dinamis Pegawai & Biaya → Input Nominal.
     *
     * @return array<int, float>  [work_unit_id => nilai bulanan]
     */
    public function componentByUnit(FiscalYear $year, string $key, ?string $status = null): array
    {
        $matrix = $this->componentMatrixCache[$year->id] ??= (function () use ($year) {
            $this->assumptions = $year->assumptions()->pluck('value', 'code')->all();

            $out = [];
            Employee::with(['workUnit', 'salaryGrade'])
                ->where('is_active', true)
                ->whereNotNull('work_unit_id')
                ->get()
                ->each(function ($e) use (&$out) {
                    $row = $this->costRow($e);
                    $cell = &$out[$e->work_unit_id][$e->status];
                    foreach ($row['components'] as $componentKey => $value) {
                        $cell[$componentKey] = ($cell[$componentKey] ?? 0) + $value;
                    }
                    // THP disimpan setara tahunan agar pembagi 12 konsisten
                    $cell['thp'] = ($cell['thp'] ?? 0) + 12 * $row['thp'];
                });

            return $out;
        })();

        $byUnit = [];
        foreach ($matrix as $unitId => $byStatus) {
            $total = 0.0;
            foreach ($byStatus as $employeeStatus => $components) {
                if ($status !== null && $employeeStatus !== $status) {
                    continue;
                }
                $total += $components[$key] ?? 0;
            }
            if ($total != 0.0) {
                $byUnit[$unitId] = round($total / 12, 2);
            }
        }

        return $byUnit;
    }

    /**
     * Grand total DPLK per unit — iuran dana pensiun pegawai tetap:
     * DPLK /bln = tarif asumsi (default 21%) × Gaji Pokok /bln, dijumlahkan per unit.
     *
     * @return array<int, float>  [work_unit_id => total DPLK bulanan]
     */
    public function dplkByUnit(FiscalYear $year): array
    {
        $rate = (float) ($year->assumptions()->where('code', 'dplk')->value('value') ?? 21);

        return Employee::query()
            ->where('is_active', true)
            ->where('status', 'tetap')
            ->whereNotNull('work_unit_id')
            ->groupBy('work_unit_id')
            ->selectRaw('work_unit_id, SUM(base_salary) as total')
            ->pluck('total', 'work_unit_id')
            ->map(fn ($v) => round((float) $v * $rate / 100, 2))
            ->all();
    }

    // ── Model perhitungan (submenu Input Nominal) ──────────────────────────

    /** Bulan UPMK (UU 13/2003) berdasarkan masa kerja saat pensiun. */
    private function upmkMonths(int $years): int
    {
        return match (true) {
            $years < 3 => 0,
            $years < 6 => 2,
            $years < 9 => 3,
            $years < 12 => 4,
            $years < 15 => 5,
            $years < 18 => 6,
            $years < 21 => 7,
            $years < 24 => 8,
            default => 10,
        };
    }

    /**
     * Model Biaya Purnabakti (sheet "Biaya Purnabakti"): per pegawai tetap
     * ber-tanggal-lahir — pesangon 9× + UPMK × estimasi gaji terakhir
     * (THP × (1+growth)^sisa), diamortisasi ke sisa masa kerja.
     */
    public function purnabaktiModel(FiscalYear $year): array
    {
        $this->assumptions = $year->assumptions()->pluck('value', 'code')->all();
        $age = (int) $this->a('usia_pensiun', 55);
        $growth = $this->a('purnabakti_growth', 7) / 100;

        $rows = [];
        Employee::with('workUnit')
            ->where('is_active', true)
            ->where('status', 'tetap')
            ->whereNotNull('birth_date')
            ->orderBy('name')
            ->get()
            ->each(function ($e) use (&$rows, $year, $age, $growth) {
                $thp = $e->base_salary + $e->position_allowance + $e->transport_allowance;
                $pensiunYear = $e->birth_date->year + $age;
                $sisa = max(0, $pensiunYear - $year->year);
                $est = $thp * pow(1 + $growth, $sisa);
                $masaKerja = $pensiunYear - ($e->join_date?->year ?? $year->year);
                $pesangon = 9 * $est;
                $upmk = $this->upmkMonths($masaKerja) * $est;
                $grand = $pesangon + $upmk;

                $rows[] = [
                    'id' => $e->id,
                    'name' => $e->name,
                    'unit' => $e->workUnit?->code,
                    'work_unit_id' => $e->work_unit_id,
                    'birth_date' => $e->birth_date->toDateString(),
                    'join_date' => $e->join_date?->toDateString(),
                    'thp' => round($thp, 2),
                    'pensiun_year' => $pensiunYear,
                    'sisa' => $sisa,
                    'est_gaji_terakhir' => round($est, 2),
                    'masa_kerja' => $masaKerja,
                    'upmk_bulan' => $this->upmkMonths($masaKerja),
                    'pesangon' => round($pesangon, 2),
                    'upmk' => round($upmk, 2),
                    'grand_total' => round($grand, 2),
                    'biaya_tahunan' => round($grand / max(1, $sisa), 2),
                ];
            });

        return [
            'rows' => $rows,
            'meta' => ['usia_pensiun' => $age, 'growth' => $growth * 100, 'pesangon_bulan' => 9],
        ];
    }

    /**
     * Model Tunjangan Cuti: per pegawai tetap dengan kriteria Bulan & Hak
     * Cuti — nominal = THP × 2 (hak "3 THN") atau THP × 1 (hak "THN"),
     * dibukukan pada bulan cuti masing-masing.
     */
    public function cutiModel(FiscalYear $year): array
    {
        $this->assumptions = $year->assumptions()->pluck('value', 'code')->all();

        $rows = [];
        Employee::with('workUnit')
            ->where('is_active', true)
            ->where('status', 'tetap')
            ->whereNotNull('cuti_month')
            ->whereNotNull('cuti_entitlement')
            ->orderBy('name')
            ->get()
            ->each(function ($e) use (&$rows) {
                $thp = $e->base_salary + $e->position_allowance + $e->transport_allowance;
                $rows[] = [
                    'id' => $e->id,
                    'name' => $e->name,
                    'unit' => $e->workUnit?->code,
                    'work_unit_id' => $e->work_unit_id,
                    'bulan' => $e->cuti_month,
                    'hak' => $e->cuti_entitlement,
                    'thp' => round($thp, 2),
                    'nominal' => round($this->cutiNominal($e, $thp), 2),
                ];
            });

        return ['rows' => $rows];
    }

    /**
     * Model PPh 21 TER: per pegawai (semua status) — bruto bulanan × tarif
     * efektif rata-rata sesuai kategori PTKP.
     */
    public function pphModel(FiscalYear $year): array
    {
        $this->assumptions = $year->assumptions()->pluck('value', 'code')->all();

        $rows = [];
        Employee::with('workUnit')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->each(function ($e) use (&$rows) {
                $base = $e->base_salary;
                $thp = $base + $e->position_allowance + $e->transport_allowance;
                $components = $this->baseComponents($e, $base, $thp);
                $months = $this->pphMonths($e, $components, $thp);
                $total = array_sum($months);

                $rows[] = [
                    'id' => $e->id,
                    'name' => $e->name,
                    'unit' => $e->workUnit?->code,
                    'work_unit_id' => $e->work_unit_id,
                    'status' => $e->status,
                    'ptkp' => $e->ptkp_status ?? 'TK/0',
                    'kategori' => $this->terCategory($e->ptkp_status),
                    'months' => array_map(fn ($v) => round($v, 2), array_values($months)),
                    'total' => round($total, 2),
                ];
            });

        return ['rows' => $rows];
    }

    /**
     * Nilai per unit per bulan (1..12) satu model perhitungan — sumber entri
     * RKAP "model:<nama>" untuk jenis biaya terkait.
     *
     * @return array<int, array<int, float>>  [work_unit_id => [bulan => nilai]]
     */
    public function modelByUnit(FiscalYear $year, string $model): array
    {
        $out = [];
        $add = function (?int $unitId, int $month, float $amount) use (&$out) {
            if (! $unitId || $amount == 0.0) {
                return;
            }
            $out[$unitId][$month] = ($out[$unitId][$month] ?? 0) + $amount;
        };

        if ($model === 'purnabakti') {
            foreach ($this->purnabaktiModel($year)['rows'] as $row) {
                for ($m = 1; $m <= 12; $m++) {
                    $add($row['work_unit_id'], $m, $row['biaya_tahunan'] / 12);
                }
            }
        } elseif ($model === 'cuti') {
            foreach ($this->cutiModel($year)['rows'] as $row) {
                $add($row['work_unit_id'], (int) $row['bulan'], $row['nominal']);
            }
        } elseif ($model === 'pph') {
            foreach ($this->pphModel($year)['rows'] as $row) {
                foreach ($row['months'] as $i => $amount) {
                    $add($row['work_unit_id'], $i + 1, $amount);
                }
            }
        }

        return $out;
    }

    /**
     * Perubahan roster (pegawai masuk/keluar/nonaktif) memengaruhi jenis biaya
     * bersumber pegawai — samakan entri seluruh tahun anggaran yang belum final.
     */
    public function syncAllOpenYears(): void
    {
        FiscalYear::where('status', '!=', 'final')->get()
            ->each(fn ($year) => $this->syncEmployeeSourcedEntries($year));
    }

    /**
     * Hitung ulang Gaji /bln seluruh pegawai dari Gaji Tahun Sebelumnya sesuai
     * asumsi kenaikan tahun ini. Besaran kenaikan dihitung dari THP tahun
     * sebelumnya (gaji + tunj. jabatan + tunj. transport):
     *   base = prev + (prev + tunj_jabatan + tunj_transport) × kenaikan%.
     * Pegawai tanpa gaji tahun sebelumnya tidak disentuh (diisi manual).
     *
     * @return int jumlah pegawai yang gajinya berubah
     */
    public function recomputeSalariesFromAssumptions(FiscalYear $year): int
    {
        $pcts = $year->assumptions()
            ->whereIn('code', array_values(self::KENAIKAN_CODES))
            ->pluck('value', 'code');

        $changed = 0;
        foreach (self::KENAIKAN_CODES as $status => $code) {
            $rate = sprintf('%.6F', (float) ($pcts[$code] ?? 0) / 100);
            $formula = "ROUND(prev_year_salary + (prev_year_salary + position_allowance + transport_allowance) * {$rate}, 2)";
            $changed += Employee::where('status', $status)
                ->where('prev_year_salary', '>', 0)
                ->whereRaw("base_salary <> {$formula}")
                ->update(['base_salary' => DB::raw($formula)]);
        }

        return $changed;
    }

    private function a(string $code, float $default = 0): float
    {
        return (float) ($this->assumptions[$code] ?? $default);
    }

    private function bpjsTkRate(): float
    {
        return $this->a('bpjs_jht', 3.7) + $this->a('bpjs_jkk', 0.24)
            + $this->a('bpjs_jkm', 0.3) + $this->a('bpjs_jp', 2);
    }

    private function costRow(Employee $e): array
    {
        $base = $e->base_salary;
        $thp = $base + $e->position_allowance + $e->transport_allowance;

        $components = $this->baseComponents($e, $base, $thp);

        // PPh 21 berbasis TER (bulanan, dijumlahkan setahun)
        $pphMonths = $this->pphMonths($e, $components, $thp);
        $components['pph21'] = array_sum($pphMonths);

        // fee & PPN honor dihitung dari subtotal termasuk PPh 21
        if ($e->status === 'honor') {
            $subtotal = array_sum($components);
            $components['fee'] = $this->a('fee_pihak3', 2) / 100 * $subtotal;
            $components['ppn'] = $this->a('ppn', 11) / 100 * ($subtotal + $components['fee']);
        }

        // rincian biaya per bulan: tunjangan (tetap), iuran & pajak bulanan,
        // fee+PPN (honor), dan rata-rata total per bulan
        $monthly = [];
        if ($e->status === 'tetap') {
            $monthly['tunj_jabatan'] = $e->position_allowance;
            $monthly['tunj_transport'] = $e->transport_allowance;
        }
        // THP = gaji pokok + tunj. jabatan + tunj. transport
        $monthly['thp'] = $thp;
        $monthly['thp_thn'] = 12 * $thp;
        if ($e->status === 'tetap') {
            $monthly['cuti'] = ($components['cuti'] ?? 0) / 12;
        }
        $monthly['bpjs_kes'] = $this->a('bpjs_kes', 4) / 100 * $base;
        $monthly['bpjs_tk'] = $this->bpjsTkRate() / 100 * $base;
        if ($e->status === 'tetap') {
            $monthly['dplk'] = $this->a('dplk', 21) / 100 * $base;
        }
        $monthly['pph21'] = $components['pph21'] / 12;
        if ($e->status === 'honor') {
            $monthly['fee'] = $components['fee'] / 12;
            $monthly['ppn'] = $components['ppn'] / 12;
        }
        $monthly['total'] = array_sum($components) / 12;

        return [
            'id' => $e->id,
            'name' => $e->name,
            'jabatan' => $e->jabatan,
            'unit' => $e->workUnit?->code,
            'unit_name' => $e->workUnit?->name,
            'work_unit_id' => $e->work_unit_id,
            'status' => $e->status,
            'grade' => $e->salaryGrade?->code,
            'grade_level' => $e->salaryGrade?->level,
            'salary_grade_id' => $e->salary_grade_id,
            'grade_source' => $e->grade_source,
            'join_date' => $e->join_date?->toDateString(),
            'birth_date' => $e->birth_date?->toDateString(),
            'ptkp_status' => $e->ptkp_status,
            'cuti_month' => $e->cuti_month,
            'cuti_entitlement' => $e->cuti_entitlement,
            'notes' => $e->notes,
            'base_salary' => $base,
            'prev_year_salary' => $e->prev_year_salary,
            'position_allowance' => $e->position_allowance,
            'transport_allowance' => $e->transport_allowance,
            'thp' => $thp,
            'components' => array_map(fn ($v) => round($v, 2), $components),
            'monthly' => array_map(fn ($v) => round($v, 2), $monthly),
            'total' => round(array_sum($components), 2),
        ];
    }

    /** Nominal tunjangan cuti setahun: THP × hak cuti (3thn → ×2, thn → ×1). */
    private function cutiNominal(Employee $e, float $thp): float
    {
        if ($e->status !== 'tetap' || ! $e->cuti_entitlement || ! $e->cuti_month) {
            return 0.0;
        }

        return $thp * ($e->cuti_entitlement === '3thn' ? 2 : 1);
    }

    /**
     * Komponen biaya dasar per status — tanpa PPh 21 (dihitung TER per bulan)
     * dan tanpa fee/PPN honor (dihitung setelah PPh masuk subtotal).
     */
    private function baseComponents(Employee $e, float $base, float $thp): array
    {
        return match ($e->status) {
            'kontrak' => [
                'gaji' => 12 * $base,
                'thr' => $this->a('thr_kontrak', 2) * $base,
                'bonus' => $this->a('bonus_kontrak', 1.5) * $base,
                'kompensasi' => $this->a('kompensasi_kontrak', 1) * $base,
                'bpjs_kes' => $this->a('bpjs_kes', 4) / 100 * 12 * $base,
                'bpjs_tk' => $this->bpjsTkRate() / 100 * 12 * $base,
            ],
            'honor' => [
                'gaji' => 12 * $base,
                'thr' => $this->a('thr_honor', 1) * $base,
                'bonus' => $this->a('bonus_honor', 0.5) * $base,
                'kompensasi' => $this->a('kompensasi_honor', 1) * $base,
                'bpjs_kes' => $this->a('bpjs_kes', 4) / 100 * 12 * $base,
                'bpjs_tk' => $this->bpjsTkRate() / 100 * 12 * $base,
            ],
            default => (function () use ($e, $base, $thp) {
                $isDireksi = $e->status === 'direksi';
                $components = [
                    'gaji' => 12 * $base,
                    'tunj_jabatan' => 12 * $e->position_allowance,
                    'tunj_transport' => 12 * $e->transport_allowance,
                    'thr' => $this->a('thr_tetap', 2) * $thp,
                    'bonus' => $this->a($isDireksi ? 'bonus_direksi' : 'bonus_tetap', 3.5) * $thp,
                ];
                if (! $isDireksi) {
                    $components['cuti'] = $this->cutiNominal($e, $thp);
                }
                $components['bpjs_kes'] = $this->a('bpjs_kes', 4) / 100 * 12 * $base;
                $components['bpjs_tk'] = $this->bpjsTkRate() / 100 * 12 * $base;
                if (! $isDireksi) {
                    $components['dplk'] = $this->a('dplk', 21) / 100 * 12 * $base;
                }

                return $components;
            })(),
        };
    }

    // ── PPh 21 berbasis Tarif Efektif Rata-rata (TER, PP 58/2023) ──────────

    /** Kategori TER (A/B/C) dari status PTKP pegawai; tanpa PTKP → TK/0. */
    public function terCategory(?string $ptkp): string
    {
        return config('ter.categories')[$ptkp ?? 'TK/0'] ?? 'A';
    }

    /** Tarif TER untuk penghasilan bruto sebulan pada kategori terkait. */
    public function terRate(string $category, float $bruto): float
    {
        foreach (config("ter.{$category}", []) as [$min, $max, $rate]) {
            if ($bruto >= $min && ($max === null || $bruto <= $max)) {
                return (float) $rate;
            }
        }

        return 0.34;
    }

    /**
     * PPh 21 per bulan (1..12) satu pegawai, mengikuti model sheet PPH:
     * bruto = gaji (THP utk tetap) + premi JKK+JKM+BPJS Kes + THR/12 +
     * Bonus/12 + Kompensasi/12 (+ tunjangan cuti pada bulan cutinya),
     * lalu PPh = TER(kategori PTKP, bruto) × bruto.
     */
    public function pphMonths(Employee $e, array $components, float $thp): array
    {
        $isTetap = in_array($e->status, ['tetap', 'direksi'], true);
        $gaji = $isTetap ? $thp : (float) $e->base_salary;
        $premi = ($this->a('bpjs_jkk', 0.24) + $this->a('bpjs_jkm', 0.3) + $this->a('bpjs_kes', 4)) / 100 * $gaji;

        $flat = $gaji + $premi
            + ($components['thr'] ?? 0) / 12
            + ($components['bonus'] ?? 0) / 12
            + ($components['kompensasi'] ?? 0) / 12;

        $cutiMonth = $e->status === 'tetap' ? $e->cuti_month : null;
        $cutiNominal = $components['cuti'] ?? 0.0;
        $category = $this->terCategory($e->ptkp_status);

        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $bruto = $flat + ($cutiMonth === $m ? $cutiNominal : 0.0);
            $out[$m] = $this->terRate($category, $bruto) * $bruto;
        }

        return $out;
    }

    private function notesFor(string $status): array
    {
        $bpjs = 'BPJS Kes '.$this->a('bpjs_kes', 4).'% + BPJS TK '.round($this->bpjsTkRate(), 2).'% dari gaji pokok';
        $pph = 'PPh 21 dihitung dengan Tarif Efektif Rata-rata (TER, PP 58/2023) dari penghasilan bruto bulanan sesuai kategori PTKP pegawai';
        $kenaikan = 'Gaji Pokok /bln = Gaji Pokok thn sebelumnya + (THP thn sebelumnya × %Kenaikan Gaji); THP = gaji pokok + tunj. jabatan + tunj. transport';

        return match ($status) {
            'kontrak' => [
                'THR '.$this->a('thr_kontrak', 2).' bln, Bonus '.$this->a('bonus_kontrak', 1.5).' bln, Kompensasi '.$this->a('kompensasi_kontrak', 1).' bln gaji',
                $bpjs,
                $pph,
                $kenaikan,
            ],
            'honor' => [
                'THR '.$this->a('thr_honor', 1).' bln, Bonus '.$this->a('bonus_honor', 0.5).' bln, Kompensasi '.$this->a('kompensasi_honor', 1).' bln gaji',
                $bpjs,
                $pph,
                'Management fee '.$this->a('fee_pihak3', 2).'% dari subtotal + PPN '.$this->a('ppn', 11).'% (referensi sheet honor "(2)")',
                $kenaikan,
            ],
            default => [
                'THR '.$this->a('thr_tetap', 2).' bln, Bonus '.$this->a('bonus_tetap', 3.5).' bln dari THP (gaji + tunjangan)',
                $bpjs,
                'DPLK '.$this->a('dplk', 21).'% dari Gaji Pokok /bln — grand total per unit menjadi nilai Iuran Dana Pensiun',
                'Tunjangan Cuti = THP × hak cuti (3 THN = ×2, THN = ×1) pada bulan cuti masing-masing pegawai',
                $pph,
                $kenaikan,
            ],
        };
    }
}
