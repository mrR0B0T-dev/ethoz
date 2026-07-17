<?php

namespace App\Services\HcRkap;

use App\Models\HcRkap\Employee;
use App\Models\HcRkap\SalaryGrade;
use Illuminate\Support\Collection;

/**
 * Grading pegawai: menentukan grade & level tiap pegawai berdasarkan posisi
 * existing di database, sebagai dasar struktur skala upah.
 *
 * Aturan penentuan (deterministik):
 *  1. Tunjangan jabatan pegawai sama persis dengan tarif tunjangan salah satu
 *     grade → grade tersebut (tarif tiap grade unik; ini pemetaan paling kuat).
 *  2. Selain itu, cari grade yang rentang gaji dasarnya (min–max) memuat gaji
 *     pegawai; bila lebih dari satu (rentang saling tumpang tindih), pilih
 *     yang mid-nya paling dekat (compa-ratio ≈ 1).
 *  3. Gaji di bawah min A-1 → A-1; di atas max F-2 → F-2 (di-flag di widget).
 */
class GradingService
{
    private ?Collection $grades = null;

    /** Seluruh grade sesuai urutan tampilan (di-cache per request). */
    public function grades(): Collection
    {
        return $this->grades ??= SalaryGrade::orderBy('sort_order')->orderBy('level')->get();
    }

    /** Tentukan grade untuk satu pegawai; null bila master grade kosong. */
    public function inferGrade(Employee $employee): ?SalaryGrade
    {
        $grades = $this->grades();
        if ($grades->isEmpty()) {
            return null;
        }

        // aturan 1: kecocokan persis tunjangan jabatan (toleransi 1 rupiah)
        if ($employee->position_allowance > 0) {
            $match = $grades->first(
                fn ($g) => abs($g->position_allowance - $employee->position_allowance) < 1
            );
            if ($match) {
                return $match;
            }
        }

        // aturan 2: rentang gaji yang memuat gaji dasar, mid terdekat
        $salary = (float) $employee->base_salary;
        $containing = $grades->filter(
            fn ($g) => $salary >= $g->salary_min && $salary <= $g->salary_max
        );
        if ($containing->isNotEmpty()) {
            return $containing->sortBy(fn ($g) => abs($salary - $g->salary_mid))->first();
        }

        // aturan 3: di luar seluruh rentang → jepit ke ujung struktur (level terendah/tertinggi)
        $byLevel = $grades->sortBy('level')->values();

        return $salary < $byLevel->first()->salary_min
            ? $byLevel->first()
            : $byLevel->last();
    }

    /**
     * Terapkan grading ke seluruh pegawai aktif. Grade yang ditetapkan manual
     * tidak ditimpa kecuali $overwriteManual.
     *
     * @return array{graded:int, skipped_manual:int}
     */
    public function applyToAll(bool $overwriteManual = false): array
    {
        $graded = 0;
        $skipped = 0;

        Employee::where('is_active', true)->chunkById(200, function ($employees) use (&$graded, &$skipped, $overwriteManual) {
            foreach ($employees as $employee) {
                if (! $overwriteManual && $employee->grade_source === 'manual') {
                    $skipped++;

                    continue;
                }
                $grade = $this->inferGrade($employee);
                if ($grade) {
                    $employee->forceFill([
                        'salary_grade_id' => $grade->id,
                        'grade_source' => 'auto',
                        // jabatan kosong diisi dari referensi jabatan grade-nya
                        'jabatan' => $employee->jabatan ?: $grade->jabatan,
                    ])->save();
                    $graded++;
                }
            }
        });

        return ['graded' => $graded, 'skipped_manual' => $skipped];
    }

    /** Tetapkan grade satu pegawai secara manual (atau lepas dengan null). */
    public function assignManual(Employee $employee, ?int $gradeId): void
    {
        $grade = $gradeId ? $this->grades()->firstWhere('id', $gradeId) : null;

        // jabatan & grade saling terkait: memilih grade menyetel jabatan
        // ke referensi jabatan grade tsb (tanpa grade, jabatan dibiarkan)
        $employee->forceFill([
            'salary_grade_id' => $gradeId,
            'grade_source' => $gradeId ? 'manual' : null,
            'jabatan' => $grade ? $grade->jabatan : $employee->jabatan,
        ])->save();
    }

    /**
     * Data lengkap halaman grading: struktur upah, pegawai + posisi dalam
     * band, titik scatter, dan statistik distribusi untuk widget keputusan.
     */
    public function pageData(): array
    {
        $grades = $this->grades();
        $employees = Employee::with(['workUnit', 'salaryGrade'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $points = [];
        $perGrade = $grades->mapWithKeys(fn ($g) => [$g->id => [
            'code' => $g->code,
            'level' => $g->level,
            'headcount' => 0,
            'sum_compa' => 0.0,
            'below_min' => 0,
            'above_max' => 0,
        ]])->all();

        $ungraded = 0;
        $sumCompa = 0.0;
        $compaCount = 0;

        $employeeRows = $employees->map(function ($e) use (&$points, &$perGrade, &$ungraded, &$sumCompa, &$compaCount) {
            $grade = $e->salaryGrade;
            $salary = (float) $e->base_salary;

            $band = null;   // posisi terhadap band: dalam | bawah | atas
            $compa = null;  // compa-ratio = gaji / mid grade

            if ($grade) {
                $compa = $grade->salary_mid > 0 ? round($salary / $grade->salary_mid, 3) : null;
                $band = $salary < $grade->salary_min ? 'bawah'
                    : ($salary > $grade->salary_max ? 'atas' : 'dalam');

                $stat = &$perGrade[$grade->id];
                $stat['headcount']++;
                if ($compa !== null) {
                    $stat['sum_compa'] += $compa;
                    $sumCompa += $compa;
                    $compaCount++;
                }
                if ($band === 'bawah') {
                    $stat['below_min']++;
                }
                if ($band === 'atas') {
                    $stat['above_max']++;
                }

                $points[] = [
                    'id' => $e->id,
                    'name' => $e->name,
                    'level' => $grade->level,
                    'code' => $grade->code,
                    'salary' => $salary,
                    'band' => $band,
                ];
            } else {
                $ungraded++;
            }

            return [
                'id' => $e->id,
                'name' => $e->name,
                'jabatan' => $e->jabatan,
                'unit' => $e->workUnit?->code,
                'status' => $e->status,
                'base_salary' => $salary,
                'position_allowance' => (float) $e->position_allowance,
                'transport_allowance' => (float) $e->transport_allowance,
                'salary_grade_id' => $e->salary_grade_id,
                'grade_code' => $grade?->code,
                'grade_level' => $grade?->level,
                'grade_source' => $e->grade_source,
                'compa' => $compa,
                'band' => $band,
            ];
        })->values()->all();

        $gradeStats = collect($perGrade)->map(fn ($s) => [
            'code' => $s['code'],
            'level' => $s['level'],
            'headcount' => $s['headcount'],
            'avg_compa' => $s['headcount'] && $s['sum_compa'] > 0
                ? round($s['sum_compa'] / $s['headcount'], 2)
                : null,
            'below_min' => $s['below_min'],
            'above_max' => $s['above_max'],
        ])->values()->all();

        return [
            'grades' => $grades->map(fn ($g) => $g->only([
                'id', 'jabatan', 'code', 'level',
                'salary_min', 'salary_mid', 'salary_max',
                'position_allowance', 'transport_allowance',
            ]))->values()->all(),
            'employees' => $employeeRows,
            'points' => $points,
            'stats' => [
                'total' => count($employeeRows),
                'graded' => count($employeeRows) - $ungraded,
                'ungraded' => $ungraded,
                'avg_compa' => $compaCount ? round($sumCompa / $compaCount, 2) : null,
                'below_min' => array_sum(array_column($gradeStats, 'below_min')),
                'above_max' => array_sum(array_column($gradeStats, 'above_max')),
                'per_grade' => $gradeStats,
            ],
        ];
    }
}