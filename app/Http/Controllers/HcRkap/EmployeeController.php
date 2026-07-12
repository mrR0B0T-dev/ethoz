<?php

namespace App\Http\Controllers\HcRkap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HcRkap\Concerns\ResolvesFiscalYear;
use App\Models\HcRkap\Employee;
use App\Services\HcRkap\BudgetService;
use App\Services\HcRkap\EmployeeCostService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    use ResolvesFiscalYear;

    public function __construct(
        private EmployeeCostService $costs,
        private BudgetService $budget,
    ) {}

    public function index(Request $request)
    {
        $year = $this->resolveYear($request);

        return Inertia::render('HcRkap/Pegawai', [
            'tahun' => $year,
            'years' => $this->yearOptions(),
            'byStatus' => $this->costs->forYear($year),
            'options' => [
                'units' => $this->budget->units()
                    ->map(fn ($u) => $u->only('id', 'code', 'name', 'type', 'parent_id'))->values(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Employee::create($this->validated($request));

        return back()->with('success', 'Pegawai ditambahkan.');
    }

    public function update(Request $request, Employee $employee)
    {
        $employee->update($this->validated($request));

        return back()->with('success', 'Data pegawai diperbarui.');
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();

        return back()->with('success', 'Pegawai dihapus.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'work_unit_id' => ['nullable', 'exists:hc_work_units,id'],
            'status' => ['required', Rule::in(['tetap', 'kontrak', 'honor', 'direksi'])],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'position_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'join_date' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ]);
    }
}
