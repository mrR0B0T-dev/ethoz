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
                'grades' => $this->grading->grades()->map(fn ($g) => $g->only(
                    'id', 'jabatan', 'code', 'level',
                    'salary_min', 'salary_mid', 'salary_max',
                    'position_allowance', 'transport_allowance',
                ))->values(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $employee = Employee::create($this->applyGradeRules($data));
        $this->autoGrade($employee);
        $this->syncRosterSourcedBudgets();

        return back()->with('success', 'Pegawai ditambahkan.');
    }

    public function update(Request $request, Employee $employee)
    {
        $data = $this->validated($request);
        $employee->update($this->applyGradeRules($data));
        $this->autoGrade($employee);
        $this->syncRosterSourcedBudgets();

        return back()->with('success', 'Data pegawai diperbarui.');
    }

    /**
     * Terapkan aturan skala upah bila grade dipilih pada form:
     * gaji dasar wajib dalam rentang min–max grade, tunjangan jabatan &
     * transport mengikuti tarif grade, jabatan kosong diisi referensi grade.
     * Tanpa grade → salary_grade_id dilepas agar grading otomatis berjalan.
     */
    private function applyGradeRules(array $data): array
    {
        if (empty($data['salary_grade_id'])) {
            $data['salary_grade_id'] = null;
            $data['grade_source'] = null;

            return $data;
        }

        $grade = $this->grading->grades()->firstWhere('id', (int) $data['salary_grade_id']);

        if ((float) $data['base_salary'] < $grade->salary_min
            || (float) $data['base_salary'] > $grade->salary_max) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'base_salary' => sprintf(
                    'Gaji dasar harus dalam rentang skala upah grade %s: Rp %s – Rp %s.',
                    $grade->code,
                    number_format($grade->salary_min, 0, ',', '.'),
                    number_format($grade->salary_max, 0, ',', '.'),
                ),
            ]);
        }

        $data['position_allowance'] = $grade->position_allowance;
        $data['transport_allowance'] = $grade->transport_allowance;
        $data['jabatan'] = $data['jabatan'] ?: $grade->jabatan;
        $data['grade_source'] = 'manual'; // dipilih pengguna → tidak ditimpa grading otomatis

        return $data;
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
            'salary_grade_id' => ['nullable', 'exists:hc_salary_grades,id'],
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
