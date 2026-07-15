<?php

namespace Database\Seeders;

use App\Models\HcRkap\Employee;
use App\Models\HcRkap\FiscalYear;
use App\Models\HcRkap\WorkUnit;
use App\Services\HcRkap\EmployeeCostService;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ganti seluruh data pegawai (menu "Pegawai & Biaya") dengan data dummy
 * anonim yang tetap realistis: nama Indonesia acak, sebaran unit & status
 * mengikuti struktur asli, dan nominal mengikuti tabel golongan gaji.
 *
 * Jalankan: php artisan db:seed --class=DummyEmployeeSeeder
 *
 * Efek samping:
 *  - hc_employees dikosongkan lalu diisi ulang dengan data dummy;
 *  - database/seeders/data/hc_rkap/employees.json ditulis ulang agar
 *    `migrate:fresh --seed` juga menghasilkan data dummy;
 *  - jenis biaya bersumber pegawai (Biaya Gaji Dasar) disinkronkan ulang
 *    untuk tahun anggaran yang belum final.
 */
class DummyEmployeeSeeder extends Seeder
{
    /**
     * Tabel golongan: [base_min, base_max, tunj_jabatan, tunj_transport, bobot].
     * Nilai tunjangan mengikuti grade nyata agar tampil autentik.
     */
    private const GRADES = [
        [3_400_000, 4_500_000, 553_231, 1_650_000, 10],
        [4_500_000, 6_000_000, 608_554, 1_650_000, 14],
        [5_000_000, 7_000_000, 669_410, 1_650_000, 12],
        [6_000_000, 8_500_000, 1_237_069, 2_618_000, 9],
        [7_000_000, 10_000_000, 1_360_776, 2_618_000, 8],
        [7_500_000, 11_000_000, 1_496_854, 2_618_000, 6],
        [9_500_000, 15_000_000, 2_524_694, 2_618_000, 4],
        [10_000_000, 16_000_000, 3_054_880, 2_865_500, 3],
        [14_000_000, 20_000_000, 5_498_783, 3_597_000, 2],
        [20_000_000, 28_000_000, 6_048_662, 3_597_000, 1],
        [28_000_000, 36_000_000, 11_532_781, 4_250_400, 1],
    ];

    private const TARGET = [
        'tetap' => 142,
        'kontrak' => 113,
        'honor' => 228,
    ];

    /** Unit kantor pusat (per departemen) — pemakai utama pegawai tetap. */
    private const DEPT_CODES = [
        'BMG-GM', 'BMG-MM', 'PMG', 'BCN', 'OSM', 'SMG',
        'HCS', 'FAC', 'BAC', 'DSP', 'IAT', 'BOD',
    ];

    /** Cabang — dipakai sebagian pegawai kontrak & honor. */
    private const BRANCH_CODES = [
        'DENPASAR', 'MAKASSAR', 'BANDUNG', 'MEDAN',
        'SURABAYA', 'SEMARANG', 'PALEMBANG', 'BANJARMASIN',
    ];

    public function run(): void
    {
        $faker = FakerFactory::create('id_ID');
        $faker->seed(20260715); // reproducible

        $unitIdByCode = WorkUnit::pluck('id', 'code');
        foreach ([...self::DEPT_CODES, ...self::BRANCH_CODES] as $code) {
            if (! isset($unitIdByCode[$code])) {
                $this->command?->warn("Unit {$code} tidak ditemukan, dilewati.");
            }
        }

        $rows = [];
        $now = now();
        $push = function (array $attrs) use (&$rows, $now, $unitIdByCode) {
            $rows[] = [
                'name' => $attrs['name'],
                'work_unit_id' => $unitIdByCode[$attrs['unit']] ?? null,
                'status' => $attrs['status'],
                'base_salary' => $attrs['base_salary'],
                'position_allowance' => $attrs['position_allowance'],
                'transport_allowance' => $attrs['transport_allowance'],
                'join_date' => $attrs['join_date'],
                'notes' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        };

        // ── Pegawai tetap: tersebar merata di seluruh departemen ────────────
        // Tiap departemen punya 1 pimpinan (grade tinggi) + staf grade menengah.
        $deptCursor = 0;
        for ($i = 0; $i < self::TARGET['tetap']; $i++) {
            $unit = self::DEPT_CODES[$deptCursor % count(self::DEPT_CODES)];
            $isLead = $i < count(self::DEPT_CODES); // satu pimpinan per departemen dulu
            $grade = $isLead
                ? self::GRADES[$faker->numberBetween(8, 10)]
                : $this->weightedGrade($faker);
            $push([
                'name' => $this->cleanName($faker),
                'unit' => $unit,
                'status' => 'tetap',
                'base_salary' => round($faker->randomFloat(2, $grade[0], $grade[1]), 2),
                'position_allowance' => (float) $grade[2],
                'transport_allowance' => (float) $grade[3],
                'join_date' => null,
            ]);
            $deptCursor++;
        }

        // ── Pegawai kontrak: departemen + sebagian cabang, tanpa tunjangan ──
        $kontrakUnits = [...self::DEPT_CODES, ...self::BRANCH_CODES];
        for ($i = 0; $i < self::TARGET['kontrak']; $i++) {
            $unit = $kontrakUnits[$i % count($kontrakUnits)];
            // ~1 dari 6 kontrak adalah tenaga ahli bernilai tinggi
            $base = $faker->numberBetween(1, 6) === 1
                ? round($faker->randomFloat(2, 15_000_000, 25_000_000), 2)
                : round($faker->randomFloat(2, 5_000_000, 7_500_000), 2);
            $push([
                'name' => $this->cleanName($faker),
                'unit' => $unit,
                'status' => 'kontrak',
                'base_salary' => $base,
                'position_allowance' => 0.0,
                'transport_allowance' => 0.0,
                'join_date' => $this->joinDate($faker),
            ]);
        }

        // ── Honor / outsource: paling banyak, tersebar termasuk cabang ──────
        $honorUnits = [...self::DEPT_CODES, ...self::BRANCH_CODES];
        for ($i = 0; $i < self::TARGET['honor']; $i++) {
            $unit = $honorUnits[$i % count($honorUnits)];
            $push([
                'name' => $this->cleanName($faker),
                'unit' => $unit,
                'status' => 'honor',
                'base_salary' => round($faker->randomFloat(2, 4_000_000, 8_000_000), 2),
                'position_allowance' => 0.0,
                'transport_allowance' => 0.0,
                'join_date' => $this->joinDate($faker),
            ]);
        }

        // ── Tulis ke database (ganti penuh) ─────────────────────────────────
        DB::transaction(function () use ($rows) {
            Employee::query()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                Employee::insert($chunk);
            }
        });
        $this->command?->info(count($rows).' pegawai dummy dibuat ('
            .implode(', ', array_map(fn ($s, $n) => "{$s}={$n}", array_keys(self::TARGET), self::TARGET)).').');

        // ── Tulis ulang employees.json agar re-seed tetap dummy ─────────────
        $this->writeJson($rows, WorkUnit::pluck('code', 'id'));

        // ── Sinkronkan Biaya Gaji Dasar (bersumber pegawai) ─────────────────
        $sync = app(EmployeeCostService::class);
        FiscalYear::where('status', '!=', 'final')->get()
            ->each(fn ($year) => $sync->syncEmployeeSourcedEntries($year));
        $this->command?->info('Biaya bersumber pegawai disinkronkan untuk tahun non-final.');
    }

    /** Pilih grade dengan bobot (staf & officer lebih sering muncul). */
    private function weightedGrade(\Faker\Generator $faker): array
    {
        $total = array_sum(array_column(self::GRADES, 4));
        $pick = $faker->numberBetween(1, $total);
        $acc = 0;
        foreach (self::GRADES as $grade) {
            $acc += $grade[4];
            if ($pick <= $acc) {
                return $grade;
            }
        }

        return self::GRADES[0];
    }

    /** Nama Indonesia tanpa gelar depan/belakang agar rapi. */
    private function cleanName(\Faker\Generator $faker): string
    {
        $name = $faker->name();
        $strip = ['dr. ', 'Dr. ', 'drg. ', 'Drs. ', 'Dra. ', 'H. ', 'Hj. ', 'Ir. ', 'R. ', 'KH. '];
        $name = str_replace($strip, '', $name);
        $name = preg_replace('/,? (S\.[A-Za-z.]+|M\.[A-Za-z.]+|Ph\.D|B\.[A-Za-z.]+|A\.Md|S\.T|S\.E|S\.H|S\.Kom|M\.M|M\.Kom)\.?$/', '', $name);

        return trim($name) ?: 'Pegawai '.$faker->numberBetween(100, 999);
    }

    private function joinDate(\Faker\Generator $faker): string
    {
        return $faker->dateTimeBetween('2024-01-01', '2025-12-01')->format('Y-m-d');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  \Illuminate\Support\Collection<int, string>  $codeById
     */
    private function writeJson(array $rows, $codeById): void
    {
        $out = array_map(fn ($r) => [
            'name' => $r['name'],
            'unit' => $r['work_unit_id'] ? ($codeById[$r['work_unit_id']] ?? null) : null,
            'status' => $r['status'],
            'base_salary' => $r['base_salary'],
            'position_allowance' => $r['position_allowance'],
            'transport_allowance' => $r['transport_allowance'],
            'join_date' => $r['join_date'],
        ], $rows);

        $path = database_path('seeders/data/hc_rkap/employees.json');
        file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->command?->info('employees.json diperbarui dengan data dummy.');
    }
}
