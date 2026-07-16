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

        $allowed = ['base_salary', 'position_allowance', 'transport_allowance'];

        DB::transaction(function () use ($sourced, $year, $allowed) {
            $now = now();
            foreach ($sourced as $type) {
                if (! in_array($type->employee_source, $allowed, true)) {
                    continue;
                }

                // ganti penuh: hapus entri lama komponen ini lalu tulis dari data pegawai
                BudgetEntry::where('fiscal_year_id', $year->id)
                    ->where('cost_type_id', $type->id)
                    ->where('scenario', 'rkap')
                    ->delete();

                $rows = [];
                foreach ($this->sumByUnit($type->employee_source) as $unitId => $monthly) {
                    if ($monthly == 0.0) {
                        continue;
                    }
                    for ($m = 1; $m <= 12; $m++) {
                        $rows[] = [
                            'fiscal_year_id' => $year->id,
                            'cost_type_id' => $type->id,
                            'work_unit_id' => $unitId,
                            'month' => $m,
                            'scenario' => 'rkap',
                            'amount' => $monthly,
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

        $components = match ($e->status) {
            'kontrak' => $this->kontrakComponents($base),
            'honor' => $this->honorComponents($base),
            default => $this->tetapComponents($e, $thp),
        };

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
            'notes' => $e->notes,
            'base_salary' => $base,
            'position_allowance' => $e->position_allowance,
            'transport_allowance' => $e->transport_allowance,
            'thp' => $thp,
            'components' => array_map(fn ($v) => round($v, 2), $components),
            'total' => round(array_sum($components), 2),
        ];
    }

    private function tetapComponents(Employee $e, float $thp): array
    {
        $isDireksi = $e->status === 'direksi';
        $base = $e->base_salary;

        return [
            'gaji' => 12 * $base,
            'tunj_jabatan' => 12 * $e->position_allowance,
            'tunj_transport' => 12 * $e->transport_allowance,
            'thr' => $this->a('thr_tetap', 2) * $thp,
            'bonus' => $this->a($isDireksi ? 'bonus_direksi' : 'bonus_tetap', 3.5) * $thp,
            'bpjs_kes' => $this->a('bpjs_kes', 4) / 100 * 12 * $base,
            'bpjs_tk' => $this->bpjsTkRate() / 100 * 12 * $base,
        ];
    }

    private function kontrakComponents(float $base): array
    {
        return [
            'gaji' => 12 * $base,
            'thr' => $this->a('thr_kontrak', 2) * $base,
            'bonus' => $this->a('bonus_kontrak', 1.5) * $base,
            'kompensasi' => $this->a('kompensasi_kontrak', 1) * $base,
            'bpjs_kes' => $this->a('bpjs_kes', 4) / 100 * 12 * $base,
            'bpjs_tk' => $this->bpjsTkRate() / 100 * 12 * $base,
        ];
    }

    private function honorComponents(float $base): array
    {
        $components = [
            'gaji' => 12 * $base,
            'thr' => $this->a('thr_honor', 1) * $base,
            'bonus' => $this->a('bonus_honor', 0.5) * $base,
            'kompensasi' => $this->a('kompensasi_honor', 1) * $base,
            'bpjs_kes' => $this->a('bpjs_kes', 4) / 100 * 12 * $base,
            'bpjs_tk' => $this->bpjsTkRate() / 100 * 12 * $base,
        ];

        $subtotal = array_sum($components);
        $fee = $this->a('fee_pihak3', 2) / 100 * $subtotal;
        $components['fee'] = $fee;
        $components['ppn'] = $this->a('ppn', 11) / 100 * ($subtotal + $fee);

        return $components;
    }

    private function notesFor(string $status): array
    {
        $bpjs = 'BPJS Kes '.$this->a('bpjs_kes', 4).'% + BPJS TK '.round($this->bpjsTkRate(), 2).'% dari gaji dasar';

        return match ($status) {
            'kontrak' => [
                'THR '.$this->a('thr_kontrak', 2).' bln, Bonus '.$this->a('bonus_kontrak', 1.5).' bln, Kompensasi '.$this->a('kompensasi_kontrak', 1).' bln gaji',
                $bpjs,
            ],
            'honor' => [
                'THR '.$this->a('thr_honor', 1).' bln, Bonus '.$this->a('bonus_honor', 0.5).' bln, Kompensasi '.$this->a('kompensasi_honor', 1).' bln gaji',
                $bpjs,
                'Management fee '.$this->a('fee_pihak3', 2).'% dari subtotal + PPN '.$this->a('ppn', 11).'% (referensi sheet honor "(2)")',
            ],
            default => [
                'THR '.$this->a('thr_tetap', 2).' bln, Bonus '.$this->a('bonus_tetap', 3.5).' bln dari THP (gaji + tunjangan)',
                $bpjs,
            ],
        };
    }
}
