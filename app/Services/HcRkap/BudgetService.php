<?php

namespace App\Services\HcRkap;

use App\Models\HcRkap\BudgetEntry;
use App\Models\HcRkap\CostType;
use App\Models\HcRkap\FiscalYear;
use App\Models\HcRkap\WorkUnit;
use Illuminate\Support\Collection;

class BudgetService
{
    private ?Collection $costTypes = null;

    private ?Collection $units = null;

    /** Semua jenis biaya (di-cache selama satu request). */
    public function costTypes(): Collection
    {
        return $this->costTypes ??= CostType::orderBy('sort_order')->get();
    }

    /** Semua unit kerja (di-cache selama satu request). */
    public function units(): Collection
    {
        return $this->units ??= WorkUnit::orderBy('sort_order')->get();
    }

    /** ID kategori induk (akar) dari sebuah jenis biaya. */
    public function rootCostTypeId(int $costTypeId): int
    {
        $byId = $this->costTypes()->keyBy('id');
        $current = $byId->get($costTypeId);
        while ($current && $current->parent_id && $byId->has($current->parent_id)) {
            $current = $byId->get($current->parent_id);
        }

        return $current?->id ?? $costTypeId;
    }

    /** ID jenis biaya beserta seluruh turunannya. */
    public function costTypeDescendantIds(int $id): array
    {
        $ids = [$id];
        $queue = [$id];
        $byParent = $this->costTypes()->groupBy('parent_id');
        while ($queue) {
            $current = array_shift($queue);
            foreach ($byParent->get($current, collect()) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    /**
     * Agregat entri anggaran: baris per (cost_type_id, work_unit_id, month, scenario).
     *
     * @param  array{cost_type_ids?: array, unit_ids?: array, month_start?: int, month_end?: int, scenarios?: array}  $filters
     */
    public function aggregate(int $fiscalYearId, array $filters = []): Collection
    {
        return BudgetEntry::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->when($filters['cost_type_ids'] ?? null, fn ($q, $ids) => $q->whereIn('cost_type_id', $ids))
            ->when($filters['unit_ids'] ?? null, fn ($q, $ids) => $q->whereIn('work_unit_id', $ids))
            ->when($filters['month_start'] ?? null, fn ($q, $m) => $q->where('month', '>=', $m))
            ->when($filters['month_end'] ?? null, fn ($q, $m) => $q->where('month', '<=', $m))
            ->when($filters['scenarios'] ?? null, fn ($q, $s) => $q->whereIn('scenario', $s))
            ->selectRaw('cost_type_id, work_unit_id, month, scenario, SUM(amount) as amount')
            ->groupBy('cost_type_id', 'work_unit_id', 'month', 'scenario')
            ->get();
    }

    /**
     * Data lengkap dashboard: KPI, tren bulanan, komposisi kategori, ranking unit.
     */
    public function dashboard(FiscalYear $year, array $filters): array
    {
        $monthStart = max(1, min(12, (int) ($filters['month_start'] ?? 1)));
        $monthEnd = max($monthStart, min(12, (int) ($filters['month_end'] ?? 12)));

        $costTypeIds = ! empty($filters['cost_type_id'])
            ? $this->costTypeDescendantIds((int) $filters['cost_type_id'])
            : null;
        $unitIds = ! empty($filters['unit_id'])
            ? WorkUnit::descendantIds((int) $filters['unit_id'])
            : null;

        $base = ['cost_type_ids' => $costTypeIds, 'unit_ids' => $unitIds];
        $rows = $this->aggregate($year->id, $base);

        $prevYear = FiscalYear::where('year', $year->year - 1)->first();
        $prevRows = $prevYear ? $this->aggregate($prevYear->id, $base) : collect();

        $inRange = fn ($r) => $r->month >= $monthStart && $r->month <= $monthEnd;

        $sum = fn (Collection $set, string $scenario, bool $rangeOnly = true) => (float) $set
            ->filter(fn ($r) => $r->scenario === $scenario && (! $rangeOnly || $inRange($r)))
            ->sum('amount');

        $rkap = $sum($rows, 'rkap');
        $realisasi = $sum($rows, 'realisasi');
        $prognosa = $sum($rows, 'prognosa');
        $prevRkap = $sum($prevRows, 'rkap');
        $prevPrognosa = $sum($prevRows, 'prognosa');

        // Tren bulanan selalu 12 bulan penuh agar pola tahunan terlihat.
        $monthly = collect(range(1, 12))->map(fn ($m) => [
            'month' => $m,
            'rkap' => (float) $rows->filter(fn ($r) => $r->month === $m && $r->scenario === 'rkap')->sum('amount'),
            'realisasi' => (float) $rows->filter(fn ($r) => $r->month === $m && $r->scenario === 'realisasi')->sum('amount'),
            'prev_rkap' => (float) $prevRows->filter(fn ($r) => $r->month === $m && $r->scenario === 'rkap')->sum('amount'),
        ])->values();

        return [
            'kpi' => [
                'rkap' => $rkap,
                'realisasi' => $realisasi,
                'prognosa' => $prognosa,
                'serapan' => $rkap > 0 ? round($realisasi / $rkap * 100, 1) : null,
                'prev_rkap' => $prevRkap,
                'prev_prognosa' => $prevPrognosa,
                'yoy' => $prevRkap > 0 ? round(($rkap - $prevRkap) / $prevRkap * 100, 1) : null,
                'prev_year' => $prevYear?->year,
            ],
            'monthly' => $monthly,
            'by_category' => $this->byCategory($rows->filter($inRange), $prevRows->filter($inRange)),
            'by_unit' => $this->byUnit($rows->filter($inRange), $filters['unit_id'] ?? null),
        ];
    }

    /** Rekap per kategori biaya (akar pohon jenis biaya). */
    private function byCategory(Collection $rows, Collection $prevRows): array
    {
        $roots = $this->costTypes()->whereNull('parent_id')->sortBy('sort_order');
        $rootOf = fn ($id) => $this->rootCostTypeId($id);

        $group = function (Collection $set, string $scenario) use ($rootOf) {
            $out = [];
            foreach ($set as $r) {
                if ($r->scenario !== $scenario) {
                    continue;
                }
                $root = $rootOf($r->cost_type_id);
                $out[$root] = ($out[$root] ?? 0) + $r->amount;
            }

            return $out;
        };

        $rkap = $group($rows, 'rkap');
        $realisasi = $group($rows, 'realisasi');
        $prevRkap = $group($prevRows, 'rkap');
        $totalRkap = array_sum($rkap);

        return $roots->map(function ($root) use ($rkap, $realisasi, $prevRkap, $totalRkap) {
            $r = $rkap[$root->id] ?? 0;
            $p = $prevRkap[$root->id] ?? 0;

            return [
                'id' => $root->id,
                'code' => $root->code,
                'name' => $root->name,
                'rkap' => $r,
                'realisasi' => $realisasi[$root->id] ?? 0,
                'prev_rkap' => $p,
                'yoy' => $p > 0 ? round(($r - $p) / $p * 100, 1) : null,
                'share' => $totalRkap > 0 ? round($r / $totalRkap * 100, 1) : 0,
            ];
        })->filter(fn ($row) => $row['rkap'] != 0 || $row['realisasi'] != 0 || $row['prev_rkap'] != 0)
            ->values()->all();
    }

    /**
     * Rekap per unit kerja pada level tampilan yang relevan:
     * tanpa filter → level department/group; dengan filter → anak unit terpilih.
     */
    private function byUnit(Collection $rows, $selectedUnitId): array
    {
        $units = $this->units()->keyBy('id');
        $selectedUnitId = $selectedUnitId ? (int) $selectedUnitId : null;

        // Petakan tiap unit ke "unit tampilan"
        $displayOf = function (int $unitId) use ($units, $selectedUnitId) {
            $unit = $units->get($unitId);
            if (! $unit) {
                return null;
            }
            if ($selectedUnitId) {
                // naikkan ke anak langsung dari unit terpilih (atau unit itu sendiri)
                $current = $unit;
                while ($current && $current->parent_id && $current->parent_id !== $selectedUnitId) {
                    $current = $units->get($current->parent_id);
                }

                return $current?->parent_id === $selectedUnitId ? $current : $unit;
            }
            // default: section → naikkan ke department
            return $unit->type === 'section' && $unit->parent_id
                ? $units->get($unit->parent_id) ?? $unit
                : $unit;
        };

        $out = [];
        foreach ($rows as $r) {
            $display = $displayOf($r->work_unit_id);
            if (! $display) {
                continue;
            }
            $out[$display->id] ??= [
                'id' => $display->id,
                'code' => $display->code,
                'name' => $display->name,
                'rkap' => 0.0,
                'realisasi' => 0.0,
            ];
            if ($r->scenario === 'rkap') {
                $out[$display->id]['rkap'] += $r->amount;
            } elseif ($r->scenario === 'realisasi') {
                $out[$display->id]['realisasi'] += $r->amount;
            }
        }

        return collect($out)
            ->filter(fn ($u) => $u['rkap'] != 0 || $u['realisasi'] != 0)
            ->sortByDesc('rkap')
            ->values()->all();
    }

    /**
     * Matriks detail RKAP: kategori → komponen → unit × 12 bulan
     * untuk satu skenario.
     */
    public function detailMatrix(FiscalYear $year, string $scenario, ?int $unitId = null): array
    {
        $unitIds = $unitId ? WorkUnit::descendantIds($unitId) : null;
        $rows = $this->aggregate($year->id, [
            'unit_ids' => $unitIds,
            'scenarios' => [$scenario],
        ]);

        $units = $this->units()->keyBy('id');
        $types = $this->costTypes();
        $roots = $types->whereNull('parent_id')->sortBy('sort_order')->values();

        $emptyMonths = array_fill(1, 12, 0.0);

        $buildUnitRows = function (Collection $set) use ($units, $emptyMonths) {
            $byUnit = [];
            foreach ($set as $r) {
                $byUnit[$r->work_unit_id] ??= $emptyMonths;
                $byUnit[$r->work_unit_id][$r->month] += $r->amount;
            }

            return collect($byUnit)->map(function ($months, $uid) use ($units) {
                $unit = $units->get($uid);

                return [
                    'unit_id' => (int) $uid,
                    'code' => $unit?->code,
                    'name' => $unit?->name,
                    'months' => array_values($months),
                    'total' => array_sum($months),
                ];
            })->sortByDesc('total')->values()->all();
        };

        $grand = $emptyMonths;
        $categories = [];
        foreach ($roots as $root) {
            $childIds = $this->costTypeDescendantIds($root->id);
            $catRows = $rows->whereIn('cost_type_id', $childIds);
            if ($catRows->isEmpty()) {
                continue;
            }

            $components = [];
            foreach ($types->where('parent_id', $root->id)->sortBy('sort_order') as $component) {
                $compRows = $catRows->where('cost_type_id', $component->id);
                if ($compRows->isEmpty()) {
                    continue;
                }
                $months = $emptyMonths;
                foreach ($compRows as $r) {
                    $months[$r->month] += $r->amount;
                }
                $components[] = [
                    'id' => $component->id,
                    'code' => $component->code,
                    'name' => $component->name,
                    'employee_status' => $component->employee_status,
                    'months' => array_values($months),
                    'total' => array_sum($months),
                    'units' => $buildUnitRows($compRows),
                ];
            }

            // entri yang menempel langsung di kategori (mis. data agregat tahun lalu)
            $directRows = $catRows->where('cost_type_id', $root->id);
            if ($directRows->isNotEmpty()) {
                $months = $emptyMonths;
                foreach ($directRows as $r) {
                    $months[$r->month] += $r->amount;
                }
                $components[] = [
                    'id' => $root->id,
                    'code' => $root->code,
                    'name' => $root->name.' (agregat)',
                    'employee_status' => null,
                    'months' => array_values($months),
                    'total' => array_sum($months),
                    'units' => $buildUnitRows($directRows),
                ];
            }

            $catMonths = $emptyMonths;
            foreach ($catRows as $r) {
                $catMonths[$r->month] += $r->amount;
                $grand[$r->month] += $r->amount;
            }

            $categories[] = [
                'id' => $root->id,
                'code' => $root->code,
                'name' => $root->name,
                'months' => array_values($catMonths),
                'total' => array_sum($catMonths),
                'components' => $components,
            ];
        }

        return [
            'categories' => $categories,
            'grand' => ['months' => array_values($grand), 'total' => array_sum($grand)],
        ];
    }
}
