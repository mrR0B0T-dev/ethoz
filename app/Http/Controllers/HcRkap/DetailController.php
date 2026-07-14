<?php

namespace App\Http\Controllers\HcRkap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HcRkap\Concerns\ResolvesFiscalYear;
use App\Models\HcRkap\BudgetEntry;
use App\Services\HcRkap\BudgetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DetailController extends Controller
{
    use ResolvesFiscalYear;

    public function __construct(private BudgetService $budget) {}

    public function index(Request $request)
    {
        $year = $this->resolveYear($request);
        $scenario = in_array($request->get('skenario'), ['rkap', 'realisasi', 'prognosa'])
            ? $request->get('skenario') : 'rkap';
        $unitId = $request->integer('unit') ?: null;

        return Inertia::render('HcRkap/Detail', [
            'tahun' => $year,
            'years' => $this->yearOptions(),
            'skenario' => $scenario,
            'unit' => $unitId,
            'options' => [
                'units' => $this->budget->units()
                    ->map(fn ($u) => $u->only('id', 'code', 'name', 'type', 'parent_id'))->values(),
            ],
            'matrix' => $this->budget->detailMatrix($year, $scenario, $unitId),
            // RKAP diinput lewat menu Input Nominal, realisasi lewat menu
            // Realisasi — di sini hanya prognosa yang bisa diubah langsung.
            'canEdit' => $year->status !== 'final' && $scenario === 'prognosa',
        ]);
    }

    public function upsert(Request $request)
    {
        $data = $request->validate([
            'fiscal_year_id' => ['required', 'exists:hc_fiscal_years,id'],
            'cost_type_id' => ['required', 'exists:hc_cost_types,id'],
            'work_unit_id' => ['required', 'exists:hc_work_units,id'],
            'month' => ['required', 'integer', 'between:1,12'],
            'scenario' => ['required', Rule::in(['prognosa'])],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $year = \App\Models\HcRkap\FiscalYear::findOrFail($data['fiscal_year_id']);
        if ($year->status === 'final') {
            return back()->with('error', 'Tahun anggaran sudah final dan terkunci.');
        }

        BudgetEntry::updateOrCreate(
            collect($data)->except('amount')->all(),
            ['amount' => $data['amount']]
        );

        return back()->with('success', 'Nilai anggaran tersimpan.');
    }
}
