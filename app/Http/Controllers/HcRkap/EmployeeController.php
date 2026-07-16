<?php

namespace App\Http\Controllers\HcRkap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HcRkap\Concerns\ResolvesFiscalYear;
use App\Models\HcRkap\Employee;
use App\Services\HcRkap\BudgetService;
use App\Services\HcRkap\EmployeeCostService;
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
        $employee = Employee::create($this->validated($request));
        $this->autoGrade($employee);
        $this->syncRosterSourcedBudgets();

        return back()->with('success', 'Pegawai ditambahkan.');
    }

    public function update(Request $request, Employee $employee)
    {
        $employee->update($this->validated($request));
        $this->autoGrade($employee);
        $this->syncRosterSourcedBudgets();

        return back()->with('success', 'Data pegawai diperbarui.');
    }

    /** Grading ulang otomatis setelah data gaji berubah; grade manual dipertahankan. */
    private function autoGrade(Employee $employee): void
    {
        if ($employee->grade_source === 'manual') {
            return;
        }
        $grade = $this->grading->inferGrade($employee);
        if ($grade) {
            $employee->forceFill([
                'salary_grade_id' => $grade->id,
                'grade_source' => 'auto',
                // jabatan kosong diisi dari referensi jabatan grade-nya
                'jabatan' => $employee->jabatan ?: $grade->jabatan,
            ])->save();
        }
    }

    public function destroy(Request $request, Employee $employee)
    {
        $employee->delete();
        $this->syncRosterSourcedBudgets();

        return back()->with('success', 'Pegawai dihapus.');
    }

    /** Unduh template Excel untuk menambah pegawai secara massal. */
    public function template()
    {
        $units = $this->budget->units()->where('is_active', true)->sortBy('sort_order')->values();
        $workbook = $this->spreadsheet->buildTemplate($units);

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
            'position_allowance' => $it['position_allowance'],
            'transport_allowance' => $it['transport_allowance'],
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
        $this->syncRosterSourcedBudgets();

        $message = count($rows).' pegawai berhasil diimpor.';
        if ($parsed['errors']) {
            $message .= ' '.count($parsed['errors']).' baris dilewati — '.$parsed['errors'][0];
        }

        return back()->with('success', $message);
    }

    /**
     * Perubahan roster pegawai memengaruhi jenis biaya bersumber pegawai
     * (mis. Biaya Gaji Dasar). Samakan untuk semua tahun yang belum final.
     */
    private function syncRosterSourcedBudgets(): void
    {
        \App\Models\HcRkap\FiscalYear::where('status', '!=', 'final')->get()
            ->each(fn ($year) => $this->costs->syncEmployeeSourcedEntries($year));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'jabatan' => ['nullable', 'string', 'max:150'],
            'work_unit_id' => ['nullable', 'exists:hc_work_units,id'],
            'status' => ['required', Rule::in(['tetap', 'kontrak', 'honor', 'direksi'])],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'position_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'join_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }
}
