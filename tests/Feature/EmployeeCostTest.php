<?php

namespace Tests\Feature;

use App\Models\HcRkap\Employee;
use App\Models\HcRkap\FiscalYear;
use App\Services\HcRkap\EmployeeCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perhitungan biaya pegawai: BPJS Kes & BPJS TK dihitung dari Jumlah Gaji
 * (gaji pokok + tunjangan), bukan lagi dari gaji pokok saja.
 */
class EmployeeCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_bpjs_is_computed_from_jumlah_gaji_not_base_salary(): void
    {
        $year = FiscalYear::create(['year' => 2035, 'status' => 'aktif']);

        $employee = Employee::create([
            'name' => 'Tetap BPJS',
            'status' => 'tetap',
            'base_salary' => 10_000_000,
            'position_allowance' => 2_000_000,
            'transport_allowance' => 1_000_000,
            'is_active' => true,
        ]);

        $byStatus = app(EmployeeCostService::class)->forYear($year);
        $row = collect($byStatus['tetap']['employees'])->firstWhere('id', $employee->id);

        $jumlahGaji = 13_000_000;         // 10jt + 2jt + 1jt
        $bpjsKesRate = 0.04;              // default asumsi bpjs_kes 4%

        // komponen tahunan & bulanan mengikuti Jumlah Gaji
        $this->assertEqualsWithDelta($bpjsKesRate * 12 * $jumlahGaji, $row['components']['bpjs_kes'], 0.01);
        $this->assertEqualsWithDelta($bpjsKesRate * $jumlahGaji, $row['monthly']['bpjs_kes'], 0.01);

        // bukti tidak lagi berbasis gaji pokok (10jt < 13jt)
        $this->assertGreaterThan($bpjsKesRate * 12 * 10_000_000, $row['components']['bpjs_kes']);
        $this->assertGreaterThan(0, $row['components']['bpjs_tk']);
    }

    public function test_non_tetap_bpjs_equals_base_since_no_allowances(): void
    {
        $year = FiscalYear::create(['year' => 2036, 'status' => 'aktif']);

        $employee = Employee::create([
            'name' => 'Kontrak BPJS',
            'status' => 'kontrak',
            'base_salary' => 6_000_000,
            'position_allowance' => 0,
            'transport_allowance' => 0,
            'is_active' => true,
        ]);

        $byStatus = app(EmployeeCostService::class)->forYear($year);
        $row = collect($byStatus['kontrak']['employees'])->firstWhere('id', $employee->id);

        // tanpa tunjangan, Jumlah Gaji = gaji pokok → BPJS Kes = 4% × 12 × 6jt
        $this->assertEqualsWithDelta(0.04 * 12 * 6_000_000, $row['components']['bpjs_kes'], 0.01);
    }
}
