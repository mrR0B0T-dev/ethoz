<?php

use App\Models\HcRkap\FiscalYear;
use App\Services\HcRkap\EmployeeCostService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tunjangan jabatan & transport hanya berlaku untuk pegawai tetap — status
 * lain (kontrak, honor, direksi) selalu 0. Entri biaya bersumber pegawai
 * (Biaya Tunj. Jabatan & Biaya Transport) disamakan ulang setelahnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hc_employees')
            ->where('status', '!=', 'tetap')
            ->update(['position_allowance' => 0, 'transport_allowance' => 0]);

        $costs = app(EmployeeCostService::class);
        FiscalYear::where('status', '!=', 'final')->get()
            ->each(fn ($year) => $costs->syncEmployeeSourcedEntries($year));
    }

    public function down(): void
    {
        // perbaikan data satu arah — nilai tunjangan lama tidak disimpan
    }
};
