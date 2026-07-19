<?php

namespace Database\Seeders;

use App\Models\HcRkap\Employee;
use App\Models\HcRkap\FiscalYear;
use App\Services\HcRkap\EmployeeCostService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Isi "Gaji Tahun Sebelumnya" (prev_year_salary) pegawai: diturunkan dari
 * gaji berjalan dan asumsi Kenaikan Gaji tahun aktif. Rumus gaji berjalan:
 *   Gaji /bln = prev + (prev + tunj_jabatan + tunj_transport) × kenaikan%
 * sehingga kebalikan untuk mengisi nilai awal:
 *   prev = (Gaji /bln − (tunj_jabatan + tunj_transport) × k) ÷ (1 + k).
 * Setelah terisi, gaji berjalan dihitung ulang dari rumus tersebut agar
 * konsisten, lalu entri RKAP bersumber pegawai disinkronkan.
 */
class PrevYearSalarySeeder extends Seeder
{
    public function run(): void
    {
        $year = FiscalYear::where('status', 'aktif')->orderByDesc('year')->first()
            ?? FiscalYear::orderByDesc('year')->first();
        if (! $year) {
            $this->command?->warn('Tidak ada tahun anggaran; seeder dilewati.');

            return;
        }

        $pcts = $year->assumptions()
            ->whereIn('code', array_values(EmployeeCostService::KENAIKAN_CODES))
            ->pluck('value', 'code');

        $filled = 0;
        foreach (EmployeeCostService::KENAIKAN_CODES as $status => $code) {
            $rate = sprintf('%.6F', (float) ($pcts[$code] ?? 0) / 100);
            $factor = sprintf('%.6F', 1 + (float) ($pcts[$code] ?? 0) / 100);
            $inverse = "ROUND((base_salary - (position_allowance + transport_allowance) * {$rate}) / {$factor}, 2)";
            // hanya pegawai yang belum punya nilai — isian manual tidak ditimpa
            $filled += Employee::where('status', $status)
                ->where('base_salary', '>', 0)
                ->where(fn ($q) => $q->whereNull('prev_year_salary')->orWhere('prev_year_salary', 0))
                ->update(['prev_year_salary' => DB::raw($inverse)]);
        }

        $costs = app(EmployeeCostService::class);
        $recomputed = $costs->recomputeSalariesFromAssumptions($year);
        $costs->syncAllOpenYears();

        $this->command?->info(
            "Gaji tahun sebelumnya terisi untuk {$filled} pegawai (asumsi {$year->year}); "
            ."{$recomputed} gaji berjalan dihitung ulang dari rumus kenaikan."
        );
    }
}
