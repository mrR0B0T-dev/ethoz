<?php

namespace App\Http\Controllers\HcRkap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HcRkap\Concerns\ResolvesFiscalYear;
use App\Models\HcRkap\Employee;
use App\Models\HcRkap\SalaryGrade;
use App\Services\HcRkap\GradingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Menu Grading & Struktur Upah: menentukan job grade/level tiap pegawai
 * sebagai dasar struktur skala upah (gaji dasar, tunjangan jabatan,
 * tunjangan transportasi) beserta widget analisis distribusinya.
 */
class GradingController extends Controller
{
    use ResolvesFiscalYear;

    public function __construct(private GradingService $grading) {}

    public function index(Request $request)
    {
        return Inertia::render('HcRkap/Grading', [
            'tahun' => $this->resolveYear($request),
            'years' => $this->yearOptions(),
            ...$this->grading->pageData(),
        ]);
    }

    /** Tambah grade baru pada struktur skala upah. */
    public function storeStructure(Request $request)
    {
        $data = $request->validate($this->structureRules());

        $data['sort_order'] = (int) SalaryGrade::max('sort_order') + 1;
        $grade = SalaryGrade::create($data);

        return back()->with('success', "Grade {$grade->code} ditambahkan ke struktur upah.");
    }

    /** Perbarui satu baris struktur upah (jabatan, grade, level & nominal). */
    public function updateStructure(Request $request, SalaryGrade $grade)
    {
        $data = $request->validate($this->structureRules($grade));

        $grade->update($data);

        return back()->with('success', "Struktur upah grade {$grade->code} diperbarui. Jalankan ulang grading otomatis bila perlu.");
    }

    /** Hapus satu grade; pegawai pada grade tsb dilepas grade-nya. */
    public function destroyStructure(SalaryGrade $grade)
    {
        $affected = Employee::where('salary_grade_id', $grade->id)
            ->update(['salary_grade_id' => null, 'grade_source' => null]);
        $grade->delete();

        $message = "Grade {$grade->code} dihapus dari struktur upah.";
        if ($affected) {
            $message .= " {$affected} pegawai dilepas grade-nya — jalankan ulang grading otomatis.";
        }

        return back()->with('success', $message);
    }

    /** Geser urutan tampilan baris struktur (tukar dengan tetangganya). */
    public function moveStructure(Request $request, SalaryGrade $grade)
    {
        $data = $request->validate(['arah' => ['required', 'in:naik,turun']]);

        $ordered = SalaryGrade::orderBy('sort_order')->orderBy('level')->get()->values();
        $index = $ordered->search(fn ($g) => $g->id === $grade->id);
        $swapWith = $data['arah'] === 'naik' ? $index - 1 : $index + 1;

        if ($swapWith < 0 || $swapWith >= $ordered->count()) {
            return back(); // sudah di ujung
        }

        // pastikan urutan unik & rapat dulu, lalu tukar
        foreach ($ordered as $i => $g) {
            if ($g->sort_order !== $i + 1) {
                $g->update(['sort_order' => $i + 1]);
            }
        }
        $neighbor = $ordered[$swapWith];
        [$a, $b] = [$grade->fresh()->sort_order, $neighbor->fresh()->sort_order];
        $grade->update(['sort_order' => $b]);
        $neighbor->update(['sort_order' => $a]);

        return back()->with('success', "Urutan grade {$grade->code} digeser.");
    }

    /** Aturan validasi baris struktur upah (create & update). */
    private function structureRules(?SalaryGrade $grade = null): array
    {
        return [
            'jabatan' => ['required', 'string', 'max:40'],
            'code' => ['required', 'string', 'max:8',
                Rule::unique('hc_salary_grades', 'code')->ignore($grade?->id)],
            'level' => ['required', 'integer', 'min:1', 'max:99',
                Rule::unique('hc_salary_grades', 'level')->ignore($grade?->id)],
            'salary_min' => ['required', 'numeric', 'min:0'],
            'salary_mid' => ['required', 'numeric', 'gte:salary_min'],
            'salary_max' => ['required', 'numeric', 'gte:salary_mid'],
            'position_allowance' => ['required', 'numeric', 'min:0'],
            'transport_allowance' => ['required', 'numeric', 'min:0'],
        ];
    }

    /** Terapkan grading otomatis ke seluruh pegawai aktif. */
    public function apply(Request $request)
    {
        $overwrite = $request->boolean('overwrite_manual');
        $result = $this->grading->applyToAll($overwrite);

        $message = "Grading otomatis diterapkan ke {$result['graded']} pegawai.";
        if ($result['skipped_manual']) {
            $message .= " {$result['skipped_manual']} grade manual dipertahankan.";
        }

        return back()->with('success', $message);
    }

    /** Tetapkan / lepas grade satu pegawai secara manual. */
    public function assign(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'salary_grade_id' => ['nullable', 'exists:hc_salary_grades,id'],
        ]);

        $this->grading->assignManual($employee, $data['salary_grade_id'] ?? null);

        return back()->with('success', $data['salary_grade_id']
            ? "Grade {$employee->name} ditetapkan manual."
            : "Grade {$employee->name} dilepas.");
    }
}
