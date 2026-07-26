<?php

namespace Database\Seeders;

use App\Models\HcRkap\Assumption;
use App\Models\HcRkap\BudgetEntry;
use App\Models\HcRkap\CostType;
use App\Models\HcRkap\Employee;
use App\Models\HcRkap\FiscalYear;
use App\Models\HcRkap\WorkUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed data awal Sistem Informasi RKAP HC dari workbook
 * "BDP RKAP 2026 HCM DEPARTMENT" yang sudah diekstrak ke JSON
 * (database/seeders/data/hc_rkap/*.json).
 */
class HcRkapSeeder extends Seeder
{
    private string $dataPath;

    public function run(): void
    {
        $this->dataPath = database_path('seeders/data/hc_rkap');

        if (BudgetEntry::query()->exists()) {
            $this->command?->warn('Data HC RKAP sudah ada, seeder dilewati.');

            return;
        }

        DB::transaction(function () {
            $this->seedMaster();
            $this->seedYears();
            $this->seedAssumptions();
            $this->seedBudgets();
            $this->seedEmployees();
        });

        // Biaya Gaji Dasar mengikuti data pegawai → samakan untuk tahun aktif/draft
        $sync = app(\App\Services\HcRkap\EmployeeCostService::class);
        FiscalYear::where('status', '!=', 'final')->get()
            ->each(fn ($year) => $sync->syncEmployeeSourcedEntries($year));
    }

    private function json(string $file): array
    {
        return json_decode(file_get_contents("{$this->dataPath}/{$file}"), true);
    }

    private function seedMaster(): void
    {
        $master = $this->json('master.json');

        foreach ($master['work_units'] as $i => $unit) {
            WorkUnit::updateOrCreate(['code' => $unit['code']], [
                'name' => $unit['name'],
                'type' => $unit['type'],
                'parent_id' => $unit['parent']
                    ? WorkUnit::where('code', $unit['parent'])->value('id')
                    : null,
                'sort_order' => $i,
            ]);
        }

        // Komponen Biaya Gaji yang nilainya = total field pegawai per unit
        // (dikelola di menu Pegawai & Biaya, tidak diinput manual).
        $employeeSource = [
            'GAJI.DASAR' => 'base_salary',
            'GAJI.TRANSPORT' => 'transport_allowance',
            'GAJI.JABATAN' => 'position_allowance',
            'IURAN.PENSIUN' => 'dplk',
            // komponen biaya yang juga tampil di Pegawai & Biaya → nilai per
            // unit = grand total komponen tersebut (tahunan ÷ 12 per bulan)
            'TUNJ.THR' => 'thr',
            'TUNJ.BONUS' => 'bonus',
            'TUNJ.KOMPENSASI' => 'kompensasi:kontrak',
            'TUNJ.KOMPENSASI_P3' => 'kompensasi:honor',
            'IURAN.JAMSOSTEK' => 'bpjs_tk',
            'IURAN.BPJSKES' => 'bpjs_kes',
            'HONOR.BULANAN' => 'thp:kontrak',
            'HONOR.PIHAK3' => 'thp:honor',
            // komponen bersumber model perhitungan (submenu Input Nominal) —
            // nilai per unit per bulan mengikuti hasil model, terkunci
            'LAIN.PURNABAKTI' => 'model:purnabakti',
            'TUNJ.CUTI' => 'model:cuti',
            'TUNJ.PPH21' => 'model:pph',
        ];

        // jenis biaya turunan → keterangan yang tampil pada komponen terkunci
        $derived = [
            'GAJI.DASAR' => 'Total Gaji Pokok seluruh pegawai per unit — dikelola di menu Pegawai & Biaya',
            'GAJI.TRANSPORT' => 'Total Tunj. Transport seluruh pegawai per unit — dikelola di menu Pegawai & Biaya',
            'GAJI.JABATAN' => 'Total Tunj. Jabatan seluruh pegawai per unit — dikelola di menu Pegawai & Biaya',
            // GMM & Cabang melekat pada unit tanpa pegawai → baris terkunci bernilai tetap
            'GAJI.GMM' => 'Nilai Biaya Gaji GMM terkunci mengikuti kebijakan biaya personil dan tidak diinput manual di menu Input Nominal.',
            'GAJI.CABANG' => 'Nilai Biaya Gaji Cabang terkunci mengikuti kebijakan biaya personil dan tidak diinput manual di menu Input Nominal.',
            'TUNJ.THR' => 'Total THR seluruh pegawai per unit (asumsi bulan THR × Jumlah Gaji) — dikelola di menu Pegawai & Biaya',
            'TUNJ.BONUS' => 'Total Bonus seluruh pegawai per unit (asumsi bulan bonus × Jumlah Gaji) — dikelola di menu Pegawai & Biaya',
            'TUNJ.PPH21' => 'Total PPh 21 per unit — tarif efektif rata-rata (TER) × penghasilan bruto bulanan seluruh pegawai',
            'TUNJ.CUTI' => 'Total Tunjangan Cuti per unit — Jumlah Gaji × hak cuti (3 THN = ×2, THN = ×1) pada bulan cuti masing-masing pegawai',
            'LAIN.PURNABAKTI' => 'Total Biaya Purnabakti per unit — model pesangon+UPMK (submenu Input Nominal), amortisasi sisa masa kerja',
            'TUNJ.KOMPENSASI' => 'Total Kompensasi pegawai kontrak per unit — dikelola di menu Pegawai & Biaya',
            'TUNJ.KOMPENSASI_P3' => 'Total Kompensasi pegawai honor/outsource per unit — dikelola di menu Pegawai & Biaya',
            'HONOR.BULANAN' => 'Total Jumlah Gaji /bln (gaji pokok + tunjangan) pegawai kontrak per unit — dikelola di menu Pegawai & Biaya',
            'HONOR.PIHAK3' => 'Total Jumlah Gaji /bln (gaji pokok + tunjangan) pegawai honor/outsource per unit — dikelola di menu Pegawai & Biaya',
            'IURAN.JAMSOSTEK' => 'Total BPJS TK seluruh pegawai per unit (tarif iuran × Jumlah Gaji) — dikelola di menu Pegawai & Biaya',
            'IURAN.BPJSKES' => 'Total BPJS Kes seluruh pegawai per unit (tarif iuran × Jumlah Gaji) — dikelola di menu Pegawai & Biaya',
            'IURAN.PENSIUN' => 'Total DPLK (tarif asumsi × Gaji Pokok /bln) pegawai tetap per unit — dikelola di menu Pegawai & Biaya',
        ];

        foreach ($master['cost_types'] as $type) {
            $code = $type['code'];
            CostType::updateOrCreate(['code' => $code], [
                'name' => $type['name'],
                'parent_id' => $type['parent']
                    ? CostType::where('code', $type['parent'])->value('id')
                    : null,
                'employee_status' => $type['employee_status'],
                'is_derived' => isset($derived[$code]),
                'derived_note' => $derived[$code] ?? null,
                'employee_source' => $employeeSource[$code] ?? null,
                'sort_order' => $type['sort_order'],
            ]);
        }
    }

    private function seedYears(): void
    {
        FiscalYear::updateOrCreate(['year' => 2025], [
            'label' => 'RKAP 2025',
            'status' => 'final',
            'notes' => 'Angka agregat per kategori (RKAP & Prognosa) dari sheet Asumsi workbook 2026.',
        ]);
        FiscalYear::updateOrCreate(['year' => 2026], [
            'label' => 'RKAP 2026',
            'status' => 'aktif',
            'notes' => 'Diimpor dari workbook BDP RKAP 2026 HCM DEPARTMENT (Alt7).',
        ]);
    }

    private function seedAssumptions(): void
    {
        $year = FiscalYear::where('year', 2026)->first();

        // tarif DPLK (iuran dana pensiun pegawai tetap, % dari gaji pokok)
        Assumption::updateOrCreate(
            ['fiscal_year_id' => $year->id, 'code' => 'dplk'],
            [
                'label' => 'Iuran DPLK (% dari gaji pokok)',
                'category' => 'iuran',
                'value_type' => 'persen',
                'value' => 21,
                'applies_to' => 'tetap',
            ]
        );

        foreach ($this->json('assumptions.json') as $a) {
            Assumption::updateOrCreate(
                ['fiscal_year_id' => $year->id, 'code' => $a['code']],
                [
                    'label' => $a['label'],
                    'category' => $a['group'],
                    'value_type' => $a['value_type'],
                    'value' => $a['value'],
                    'applies_to' => $a['applies_to'],
                ]
            );
        }
    }

    private function seedBudgets(): void
    {
        $unitIds = WorkUnit::pluck('id', 'code');
        $typeIds = CostType::pluck('id', 'code');
        $yearIds = FiscalYear::pluck('id', 'year');

        $rows = [];
        $now = now();

        foreach ($this->json('budget_2026.json')['entries'] as $e) {
            $rows[] = [
                'fiscal_year_id' => $yearIds[2026],
                'cost_type_id' => $typeIds[$e['cost_type']],
                'work_unit_id' => $unitIds[$e['unit']],
                'month' => $e['month'],
                'scenario' => 'rkap',
                'amount' => $e['amount'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($this->json('budget_2025.json')['entries'] as $e) {
            $rows[] = [
                'fiscal_year_id' => $yearIds[2025],
                'cost_type_id' => $typeIds[$e['cost_type']],
                'work_unit_id' => $unitIds[$e['unit']],
                'month' => $e['month'],
                'scenario' => $e['scenario'],
                'amount' => $e['amount'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            BudgetEntry::insert($chunk);
        }
    }

    private function seedEmployees(): void
    {
        $unitIds = WorkUnit::pluck('id', 'code');
        $now = now();

        $rows = array_map(fn ($e) => [
            'name' => $e['name'],
            'work_unit_id' => $e['unit'] ? $unitIds[$e['unit']] : null,
            'status' => $e['status'],
            'base_salary' => $e['base_salary'],
            'position_allowance' => $e['position_allowance'],
            'transport_allowance' => $e['transport_allowance'],
            'join_date' => $e['join_date'],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->json('employees.json'));

        foreach (array_chunk($rows, 500) as $chunk) {
            Employee::insert($chunk);
        }
    }
}
