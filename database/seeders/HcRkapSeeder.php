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

        // jenis biaya yang nominalnya mengikuti aturan berbasis
        // Gaji Dasar / Tunj. Jabatan / Tunj. Transport → tidak diinput manual
        $derived = [
            'TUNJ.THR' => 'THR = jumlah bulan THR (asumsi) × (Gaji Dasar + Tunj. Jabatan + Tunj. Transport)',
            'TUNJ.BONUS' => 'Bonus = jumlah bulan bonus (asumsi) × (Gaji Dasar + Tunj. Jabatan + Tunj. Transport)',
            'TUNJ.PPH21' => 'PPh 21 dihitung dari Gaji Dasar, Tunj. Jabatan & Tunj. Transport',
            'TUNJ.KOMPENSASI' => 'Kompensasi = jumlah bulan (asumsi) × Gaji Dasar',
            'IURAN.JAMSOSTEK' => 'BPJS Ketenagakerjaan = tarif iuran (asumsi) × Gaji Dasar',
            'IURAN.BPJSKES' => 'BPJS Kesehatan = tarif iuran (asumsi) × Gaji Dasar',
            'IURAN.PENSIUN' => 'Iuran Dana Pensiun = tarif iuran (asumsi) × Gaji Dasar',
        ];

        foreach ($master['cost_types'] as $type) {
            // Biaya Gaji Dasar = grand total Gaji Dasar seluruh pegawai per unit
            $isGaji = $type['code'] === 'GAJI.DASAR';
            CostType::updateOrCreate(['code' => $type['code']], [
                'name' => $type['name'],
                'parent_id' => $type['parent']
                    ? CostType::where('code', $type['parent'])->value('id')
                    : null,
                'employee_status' => $type['employee_status'],
                'is_derived' => isset($derived[$type['code']]) || $isGaji,
                'derived_note' => $isGaji
                    ? 'Total Gaji Dasar seluruh pegawai per unit — dikelola di menu Pegawai & Biaya'
                    : ($derived[$type['code']] ?? null),
                'employee_source' => $isGaji ? 'base_salary' : null,
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
