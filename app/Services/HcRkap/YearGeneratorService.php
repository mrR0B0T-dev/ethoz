<?php

namespace App\Services\HcRkap;

use App\Models\HcRkap\Assumption;
use App\Models\HcRkap\BudgetEntry;
use App\Models\HcRkap\CostType;
use App\Models\HcRkap\FiscalYear;
use Illuminate\Support\Facades\DB;

/**
 * Generator RKAP tahun baru: menyalin anggaran tahun sumber lalu menerapkan
 * asumsi kenaikan per status pegawai — inilah yang membuat sistem dapat
 * dipakai berulang setiap tahun.
 */
class YearGeneratorService
{
    /**
     * @param  array{tetap?: float, kontrak?: float, honor?: float, direksi?: float}  $increases  persen kenaikan per status
     */
    public function generate(
        FiscalYear $source,
        int $targetYear,
        string $basisScenario = 'rkap',
        array $increases = [],
    ): FiscalYear {
        return DB::transaction(function () use ($source, $targetYear, $basisScenario, $increases) {
            $target = FiscalYear::create([
                'year' => $targetYear,
                'label' => 'RKAP '.$targetYear,
                'status' => 'draft',
                'notes' => sprintf(
                    'Digenerate dari %s (basis %s) dengan kenaikan tetap %s%%, kontrak %s%%, honor %s%%, direksi %s%%.',
                    $source->label ?? $source->year,
                    $basisScenario,
                    $increases['tetap'] ?? 0,
                    $increases['kontrak'] ?? 0,
                    $increases['honor'] ?? 0,
                    $increases['direksi'] ?? 0,
                ),
            ]);

            // salin asumsi tahun sumber, perbarui nilai kenaikan sesuai input
            $increaseCodes = [
                'tetap' => 'kenaikan_tetap',
                'kontrak' => 'kenaikan_kontrak',
                'honor' => 'kenaikan_ump',
                'direksi' => 'kenaikan_dirkom',
            ];
            foreach ($source->assumptions as $assumption) {
                $value = $assumption->value;
                foreach ($increaseCodes as $status => $code) {
                    if ($assumption->code === $code && array_key_exists($status, $increases)) {
                        $value = (float) $increases[$status];
                    }
                }
                Assumption::create([
                    'fiscal_year_id' => $target->id,
                    'code' => $assumption->code,
                    'label' => $assumption->label,
                    'category' => $assumption->category,
                    'value_type' => $assumption->value_type,
                    'value' => $value,
                    'applies_to' => $assumption->applies_to,
                    'notes' => $assumption->notes,
                ]);
            }

            // faktor kenaikan per jenis biaya (mengikuti status pegawai komponen;
            // komponen lintas status memakai kenaikan pegawai tetap)
            // komponen ber-multi-status memakai rata-rata kenaikan status terkait
            $increaseOf = fn (string $status) => (float) ($increases[$status] ?? $increases['tetap'] ?? 0);
            $factorByStatus = function (?string $statuses) use ($increaseOf) {
                $list = $statuses ? array_filter(explode(',', $statuses)) : ['tetap'];

                return 1 + array_sum(array_map($increaseOf, $list)) / max(count($list), 1) / 100;
            };
            $factors = CostType::all()->mapWithKeys(
                fn ($t) => [$t->id => $factorByStatus($t->employee_status)]
            );

            $now = now();
            $rows = BudgetEntry::where('fiscal_year_id', $source->id)
                ->where('scenario', $basisScenario)
                ->get()
                ->map(fn ($e) => [
                    'fiscal_year_id' => $target->id,
                    'cost_type_id' => $e->cost_type_id,
                    'work_unit_id' => $e->work_unit_id,
                    'month' => $e->month,
                    'scenario' => 'rkap',
                    'amount' => round($e->amount * ($factors[$e->cost_type_id] ?? 1), 2),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            foreach ($rows->chunk(500) as $chunk) {
                BudgetEntry::insert($chunk->values()->all());
            }

            // Biaya bersumber pegawai (mis. Gaji Dasar) mengikuti roster terkini,
            // bukan hasil penggandaan basis; samakan setelah generate.
            app(EmployeeCostService::class)->syncEmployeeSourcedEntries($target);

            return $target;
        });
    }
}
