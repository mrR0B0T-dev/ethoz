<?php

namespace App\Services\HcRkap;

use App\Models\HcRkap\Employee;
use App\Models\HcRkap\FiscalYear;
use App\Services\Turnover\TurnoverService;
use Illuminate\Validation\ValidationException;

/**
 * Pendaftaran & pembaruan pegawai roster RKAP HC beserta seluruh aturan
 * skala upah dan grading. Sumber tunggal aturan agar modul RKAP HC (menu
 * Pegawai & Biaya) dan modul Turnover (form "Pegawai Masuk") berperilaku
 * identik saat menambah pegawai.
 */
class EmployeeRegistrar
{
    public function __construct(
        private EmployeeCostService $costs,
        private GradingService $grading,
    ) {}

    /**
     * Buat pegawai baru dari data tervalidasi, terapkan aturan skala upah &
     * grading, samakan anggaran bersumber pegawai, dan catat kejadian masuk
     * di modul Turnover.
     */
    public function create(array $data, ?FiscalYear $year = null): Employee
    {
        $data = $this->applySalaryFormula($data, $year);
        $employee = Employee::create($this->applyAllowanceRules($this->applyGradeRules($data)));
        $this->autoGrade($employee);
        $this->costs->syncAllOpenYears();

        // pegawai baru otomatis tercatat sebagai kejadian masuk di modul Turnover
        app(TurnoverService::class)->recordHire($employee);

        return $employee;
    }

    /** Perbarui pegawai roster dengan aturan yang sama (tanpa kejadian masuk baru). */
    public function update(Employee $employee, array $data, ?FiscalYear $year = null): Employee
    {
        $data = $this->applySalaryFormula($data, $year);
        $employee->update($this->applyAllowanceRules($this->applyGradeRules($data)));
        $this->autoGrade($employee);
        $this->costs->syncAllOpenYears();

        return $employee;
    }

    /** Tunj. Jabatan & Transport hanya untuk pegawai tetap; status lain selalu 0. */
    private function applyAllowanceRules(array $data): array
    {
        if (($data['status'] ?? null) !== 'tetap') {
            $data['position_allowance'] = 0;
            $data['transport_allowance'] = 0;
        }

        return $data;
    }

    /**
     * Gaji /bln dihitung dari Gaji Tahun Sebelumnya + kenaikan sesuai asumsi
     * tahun terpilih. Besaran kenaikan dihitung dari Jumlah Gaji tahun sebelumnya:
     *   base = prev + (prev + tunj_jabatan + tunj_transport) × kenaikan%.
     * Tanpa nilai tahun sebelumnya, gaji dasar diisi manual seperti biasa.
     */
    private function applySalaryFormula(array $data, ?FiscalYear $year): array
    {
        $prev = (float) ($data['prev_year_salary'] ?? 0);
        if ($prev > 0) {
            $year ??= $this->activeYear();
            $pct = $year ? ($this->kenaikanPct($year)[$data['status']] ?? 0) : 0;
            // tunjangan hanya dimiliki pegawai tetap; status lain bernilai 0
            $allowances = ($data['status'] ?? null) === 'tetap'
                ? (float) ($data['position_allowance'] ?? 0) + (float) ($data['transport_allowance'] ?? 0)
                : 0.0;
            $data['base_salary'] = round($prev + ($prev + $allowances) * $pct / 100, 2);
        }

        return $data;
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
            throw ValidationException::withMessages([
                'base_salary' => sprintf(
                    'Gaji pokok harus dalam rentang skala upah grade %s: Rp %s – Rp %s.',
                    $grade->code,
                    number_format($grade->salary_min, 0, ',', '.'),
                    number_format($grade->salary_max, 0, ',', '.'),
                ),
            ]);
        }

        $data['position_allowance'] = $grade->position_allowance;
        $data['transport_allowance'] = $grade->transport_allowance;
        $data['jabatan'] = ($data['jabatan'] ?? null) ?: $grade->jabatan;
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

    /** Persen kenaikan gaji per status dari asumsi tahun terpilih. */
    private function kenaikanPct(FiscalYear $year): array
    {
        $values = $year->assumptions()
            ->whereIn('code', array_values(EmployeeCostService::KENAIKAN_CODES))
            ->pluck('value', 'code');

        return collect(EmployeeCostService::KENAIKAN_CODES)
            ->map(fn ($code) => (float) ($values[$code] ?? 0))
            ->all();
    }

    /** Tahun aktif (atau terbaru) sebagai default konteks asumsi kenaikan. */
    private function activeYear(): ?FiscalYear
    {
        return FiscalYear::where('status', 'aktif')->orderByDesc('year')->first()
            ?? FiscalYear::orderByDesc('year')->first();
    }
}
