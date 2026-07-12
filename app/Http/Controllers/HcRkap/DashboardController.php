<?php

namespace App\Http\Controllers\HcRkap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HcRkap\Concerns\ResolvesFiscalYear;
use App\Services\HcRkap\BudgetService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    use ResolvesFiscalYear;

    public function __construct(private BudgetService $budget) {}

    public function index(Request $request)
    {
        $year = $this->resolveYear($request);

        $filters = [
            'month_start' => $request->integer('bulan_awal') ?: 1,
            'month_end' => $request->integer('bulan_akhir') ?: 12,
            'cost_type_id' => $request->integer('jenis_biaya') ?: null,
            'unit_id' => $request->integer('unit') ?: null,
        ];

        return Inertia::render('HcRkap/Dashboard', [
            'tahun' => $year,
            'years' => $this->yearOptions(),
            'filters' => $filters,
            'options' => [
                'costTypes' => $this->budget->costTypes()
                    ->map(fn ($t) => $t->only('id', 'code', 'name', 'parent_id'))->values(),
                'units' => $this->budget->units()
                    ->map(fn ($u) => $u->only('id', 'code', 'name', 'type', 'parent_id'))->values(),
            ],
            'data' => $this->budget->dashboard($year, $filters),
        ]);
    }
}
