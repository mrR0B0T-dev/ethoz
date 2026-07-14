<?php

namespace App\Http\Controllers\HcRkap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HcRkap\Concerns\ResolvesFiscalYear;
use App\Models\HcRkap\BudgetEntry;
use App\Models\HcRkap\CostType;
use App\Models\HcRkap\FiscalYear;
use App\Services\HcRkap\BudgetService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Menu Input Nominal — tempat mengatur nilai RKAP per jenis biaya.
 * Jenis biaya bertanda "is_derived" nilainya mengacu ke jenis biaya lain
 * sehingga tidak dapat diinput manual di sini.
 */
class NominalController extends Controller
{
    use ResolvesFiscalYear;

    public function __construct(private BudgetService $budget) {}

    public function index(Request $request)
    {
        $year = $this->resolveYear($request);
        $types = $this->budget->costTypes();

        // komponen yang bisa dipilih: seluruh jenis biaya yang punya induk
        $components = $types->whereNotNull('parent_id');
        $selectedId = $request->integer('komponen') ?: null;
        $selected = $selectedId ? $components->firstWhere('id', $selectedId) : null;
        $selected ??= $components->sortBy('sort_order')->first(fn ($t) => ! $t->is_derived);

        return Inertia::render('HcRkap/Nominal', [
            'tahun' => $year,
            'years' => $this->yearOptions(),
            'tree' => $types->whereNull('parent_id')->sortBy('sort_order')->values()
                ->map(fn ($root) => [
                    'id' => $root->id,
                    'name' => $root->name,
                    'components' => $types->where('parent_id', $root->id)->sortBy('sort_order')->values()
                        ->map(fn ($t) => $t->only('id', 'code', 'name', 'employee_status', 'is_derived', 'derived_note', 'employee_source')),
                ]),
            'selected' => $selected?->only('id', 'code', 'name', 'employee_status', 'is_derived', 'derived_note', 'employee_source'),
            'rows' => $selected ? $this->unitRows($year->id, $selected->id) : [],
            'options' => [
                'units' => $this->budget->units()->where('is_active', true)
                    ->map(fn ($u) => $u->only('id', 'code', 'name', 'type', 'parent_id'))->values(),
            ],
            'canEdit' => $year->status !== 'final' && $selected && ! $selected->is_derived,
        ]);
    }

    public function upsert(Request $request)
    {
        $data = $request->validate([
            'fiscal_year_id' => ['required', 'exists:hc_fiscal_years,id'],
            'cost_type_id' => ['required', 'exists:hc_cost_types,id'],
            'rows' => ['required', 'array'],
            'rows.*.work_unit_id' => ['required', 'exists:hc_work_units,id'],
            'rows.*.months' => ['required', 'array', 'size:12'],
            'rows.*.months.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $year = FiscalYear::findOrFail($data['fiscal_year_id']);
        if ($year->status === 'final') {
            return back()->with('error', 'Tahun anggaran sudah final dan terkunci.');
        }

        $type = CostType::findOrFail($data['cost_type_id']);
        if ($type->is_derived) {
            return back()->with('error', 'Nominal '.$type->name.' mengacu ke jenis biaya lain dan tidak dapat diinput manual.');
        }

        foreach ($data['rows'] as $row) {
            foreach ($row['months'] as $i => $amount) {
                $key = [
                    'fiscal_year_id' => $year->id,
                    'cost_type_id' => $type->id,
                    'work_unit_id' => $row['work_unit_id'],
                    'month' => $i + 1,
                    'scenario' => 'rkap',
                ];

                if ($amount === null || $amount === '') {
                    BudgetEntry::where($key)->delete();

                    continue;
                }

                BudgetEntry::updateOrCreate($key, ['amount' => $amount]);
            }
        }

        return back()->with('success', 'Nominal '.$type->name.' tersimpan.');
    }

    /** Baris nominal per unit kerja (12 bulan) untuk satu jenis biaya. */
    private function unitRows(int $yearId, int $costTypeId): array
    {
        $entries = BudgetEntry::where('fiscal_year_id', $yearId)
            ->where('cost_type_id', $costTypeId)
            ->where('scenario', 'rkap')
            ->get();

        $units = $this->budget->units()->keyBy('id');

        $byUnit = [];
        foreach ($entries as $e) {
            $byUnit[$e->work_unit_id] ??= array_fill(0, 12, null);
            $byUnit[$e->work_unit_id][$e->month - 1] = (float) $e->amount;
        }

        return collect($byUnit)->map(fn ($months, $unitId) => [
            'work_unit_id' => (int) $unitId,
            'code' => $units->get($unitId)?->code,
            'name' => $units->get($unitId)?->name,
            'months' => $months,
        ])->sortBy('code')->values()->all();
    }
}
