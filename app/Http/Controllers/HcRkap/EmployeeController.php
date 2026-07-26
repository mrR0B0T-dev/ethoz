<?php

namespace App\Http\Controllers\HcRkap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HcRkap\Concerns\ResolvesFiscalYear;
use App\Models\HcRkap\Employee;
use App\Services\HcRkap\BudgetService;
use App\Services\HcRkap\EmployeeCostService;
use App\Services\HcRkap\EmployeeRegistrar;
use App\Services\HcRkap\EmployeeSpreadsheet;
use App\Services\HcRkap\GradingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EmployeeController extends Controller
{
    use ResolvesFiscalYear;

    public function __construct(
        private EmployeeCostService $costs,
        private BudgetService $budget,
        private EmployeeSpreadsheet $spreadsheet,
        private GradingService $grading,
        private EmployeeRegistrar $registrar,
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
                'grades' => $this->grading->grades()->map(fn ($g) => $g->only(
                    'id', 'jabatan', 'code', 'level',
                    'salary_min', 'salary_mid', 'salary_max',
                    'position_allowance', 'transport_allowance',
                ))->values(),
                'kenaikan' => $this->kenaikanPct($this->resolveYear($request)),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->registrar->create($this->validated($request), $this->resolveYear($request));

        return back()->with('success', 'Pegawai ditambahkan.');
    }

    public function update(Request $request, Employee $employee)
    {
        $this->registrar->update($employee, $this->validated($request), $this->resolveYear($request));

        return back()->with('success', 'Data pegawai diperbarui.');
    }

    /** Persen kenaikan gaji per status dari asumsi tahun terpilih (info form). */
    private function kenaikanPct(\App\Models\HcRkap\FiscalYear $year): array
    {
        $values = $year->assumptions()
            ->whereIn('code', array_values(EmployeeCostService::KENAIKAN_CODES))
            ->pluck('value', 'code');

        return collect(EmployeeCostService::KENAIKAN_CODES)
            ->map(fn ($code) => (float) ($values[$code] ?? 0))
            ->all();
    }

    public function destroy(Request $request, Employee $employee)
    {
        $employee->delete();
        $this->costs->syncAllOpenYears();

        return back()->with('success', 'Pegawai dihapus.');
    }

    /** Unduh template Excel untuk menambah pegawai secara massal. */
    public function template()
    {
        $units = $this->budget->units()->where('is_active', true)->sortBy('sort_order')->values();
        $workbook = $this->spreadsheet->buildTemplate($units, $this->grading->grades());

        return response()->streamDownload(function () use ($workbook) {
            (new Xlsx($workbook))->save('php://output');
            $workbook->disconnectWorksheets();
        }, 'Template_Tambah_Pegawai.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Impor daftar pegawai baru dari template Excel yang sudah diisi. */
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ], [], ['file' => 'file Excel']);

        try {
            $parsed = $this->spreadsheet->parseImport($request->file('file')->getRealPath());
        } catch (\Throwable) {
            return back()->with('error', 'File tidak dapat dibaca. Gunakan template yang diunduh dari sistem.');
        }

        if (empty($parsed['items'])) {
            return back()->with('error', $parsed['errors']
                ? 'Tidak ada baris valid. '.$parsed['errors'][0]
                : 'Tidak ada data pegawai pada file.');
        }

        $now = now();
        $rows = array_map(fn ($it) => [
            'name' => $it['name'],
            'jabatan' => $it['jabatan'],
            'work_unit_id' => $it['work_unit_id'],
            'status' => $it['status'],
            'base_salary' => $it['base_salary'],
            // tunjangan jabatan & transport hanya untuk pegawai tetap
            'position_allowance' => $it['status'] === 'tetap' ? $it['position_allowance'] : 0,
            'transport_allowance' => $it['status'] === 'tetap' ? $it['transport_allowance'] : 0,
            'join_date' => $it['join_date'],
            'notes' => $it['notes'],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $parsed['items']);

        foreach (array_chunk($rows, 500) as $chunk) {
            Employee::insert($chunk);
        }
        $this->grading->applyToAll(); // grading otomatis pegawai baru (manual dipertahankan)
        $this->costs->syncAllOpenYears();

        // pegawai hasil impor otomatis tercatat sebagai kejadian masuk (Turnover)
        $turnover = app(\App\Services\Turnover\TurnoverService::class);
        Employee::where('created_at', $now)->get()->each(
            fn ($employee) => $turnover->recordHire($employee)
        );

        $message = count($rows).' pegawai berhasil diimpor.';
        if ($parsed['errors']) {
            $message .= ' '.count($parsed['errors']).' baris dilewati — '.$parsed['errors'][0];
        }

        return back()->with('success', $message);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'jabatan' => ['nullable', 'string', 'max:150'],
            'salary_grade_id' => ['nullable', 'exists:hc_salary_grades,id'],
            'work_unit_id' => ['nullable', 'exists:hc_work_units,id'],
            'status' => ['required', Rule::in(['tetap', 'kontrak', 'honor', 'direksi'])],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'prev_year_salary' => ['nullable', 'numeric', 'min:0'],
            'position_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'join_date' => ['nullable', 'date'],
            'birth_date' => ['nullable', 'date'],
            'ptkp_status' => ['nullable', Rule::in(['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'])],
            'cuti_month' => ['nullable', 'integer', 'between:1,12'],
            'cuti_entitlement' => ['nullable', Rule::in(['thn', '3thn'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }
}
