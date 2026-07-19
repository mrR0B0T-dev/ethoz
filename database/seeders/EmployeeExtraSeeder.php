<?php

namespace Database\Seeders;

use App\Models\HcRkap\Employee;
use App\Services\HcRkap\EmployeeCostService;
use Illuminate\Database\Seeder;

/**
 * Bagikan data dasar model perhitungan dari workbook BDP RKAP
 * (database/seeders/data/hc_rkap/employee_extra.json) ke pegawai:
 *  - tanggal lahir + TMT/join (model Biaya Purnabakti) — pegawai tetap,
 *    dibagikan berpasangan sesuai baris workbook agar masa kerja konsisten,
 *  - bulan & hak cuti (model Tunjangan Cuti) — pegawai tetap,
 *  - status PTKP (model PPh 21 TER) — semua status.
 * Roster pegawai anonim, jadi atribut dibagikan per unit kerja (urutan
 * workbook) agar sebaran per unit tetap representatif; sisa atribut
 * dibagikan global. Pegawai tanpa sumber di workbook (TMT & tanggal lahir
 * kontrak/honor) diberi tanggal sintetis deterministik dengan rentang usia
 * wajar per status (konsisten antar-run). Nilai terisi tidak ditimpa.
 */
class EmployeeExtraSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/hc_rkap/employee_extra.json');
        if (! is_file($path)) {
            $this->command?->warn('employee_extra.json tidak ditemukan; seeder dilewati.');

            return;
        }

        $data = json_decode(file_get_contents($path), true) ?: [];

        // pool per unit + sisa global; entri lahir berpasangan dengan TMT/join
        $birthByUnit = collect($data['birth'] ?? [])->groupBy('unit')->toArray();
        $cutiByUnit = collect($data['cuti'] ?? [])->groupBy('unit')->toArray();
        $ptkpByStatusUnit = collect($data['ptkp'] ?? [])->map(
            fn ($rows) => collect($rows)->groupBy('unit')->map->pluck('ptkp')->toArray()
        )->toArray();

        // peta tanggal lahir → TMT (untuk pegawai yang lahirnya sudah terisi
        // dari run sebelumnya — pasangan baris workbook dipulihkan)
        $joinByBirth = collect($data['birth'] ?? [])
            ->filter(fn ($p) => ! empty($p['join']))
            ->pluck('join', 'date')->toArray();

        $shift = function (array &$pool, ?string $unit) {
            if ($unit && ! empty($pool[$unit])) {
                return array_shift($pool[$unit]);
            }
            foreach ($pool as $u => $items) { // sisa global (unit mana pun)
                if (! empty($items)) {
                    return array_shift($pool[$u]);
                }
            }

            return null;
        };

        $filled = 0;
        $employees = Employee::with('workUnit')->where('is_active', true)
            ->orderBy('work_unit_id')->orderBy('id')->get();

        foreach ($employees as $e) {
            $unit = mb_strtoupper((string) $e->workUnit?->code);
            $fill = [];

            if ($e->status === 'tetap') {
                if (! $e->birth_date && ($pair = $shift($birthByUnit, $unit))) {
                    $fill['birth_date'] = $pair['date'];
                    if (! $e->join_date && ! empty($pair['join'])) {
                        $fill['join_date'] = $pair['join'];
                    }
                } elseif ($e->birth_date && ! $e->join_date) {
                    // lahir sudah terisi sebelumnya → TMT dari pasangan baris workbook
                    $fill['join_date'] = $joinByBirth[$e->birth_date->toDateString()] ?? null;
                }
                if (! $e->cuti_month && ($cuti = $shift($cutiByUnit, $unit))) {
                    $fill['cuti_month'] = $cuti['month'];
                    $fill['cuti_entitlement'] = $cuti['ent'];
                }
            }

            // kontrak/honor tidak punya sumber TMT di workbook → tanggal PKWT
            // sintetis deterministik (per id, stabil antar-run): kontrak 1-2
            // tahun terakhir, honor/outsource s.d. 6 tahun terakhir
            if (! $e->join_date && empty($fill['join_date']) && in_array($e->status, ['kontrak', 'honor'], true)) {
                mt_srand($e->id * 7919);
                $daysBack = $e->status === 'kontrak' ? mt_rand(30, 730) : mt_rand(30, 2190);
                $fill['join_date'] = now()->startOfYear()->subDays($daysBack)->toDateString();
                mt_srand();
            }

            // tanggal lahir tanpa sumber workbook (kontrak/honor, atau tetap
            // saat pool workbook habis) → sintetis deterministik dengan
            // rentang usia wajar per status
            if (! $e->birth_date && empty($fill['birth_date'])) {
                mt_srand($e->id * 104729);
                $age = match ($e->status) {
                    'kontrak' => mt_rand(22, 40),
                    'honor' => mt_rand(20, 50),
                    default => mt_rand(25, 54),
                };
                $fill['birth_date'] = now()->startOfYear()
                    ->subYears($age)->subDays(mt_rand(0, 364))->toDateString();
                mt_srand();
            }

            if (! $e->ptkp_status && isset($ptkpByStatusUnit[$e->status])
                && ($ptkp = $shift($ptkpByStatusUnit[$e->status], $unit))) {
                $fill['ptkp_status'] = $ptkp;
            }

            $fill = array_filter($fill, fn ($v) => $v !== null);

            if ($fill) {
                $e->forceFill($fill)->save();
                $filled++;
            }
        }

        // nilai model (PPh TER, cuti, purnabakti) memengaruhi entri RKAP
        app(EmployeeCostService::class)->syncAllOpenYears();

        $this->command?->info("Data model perhitungan terisi untuk {$filled} pegawai.");
    }
}
