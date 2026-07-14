<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis biaya yang nilainya adalah grand total dari data pegawai (per unit).
 * Contoh: Biaya Gaji Dasar = jumlah Gaji Dasar seluruh pegawai per unit kerja.
 * Kolom employee_source menyimpan field pegawai yang dijumlahkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hc_cost_types', function (Blueprint $table) {
            // base_salary | position_allowance | transport_allowance
            $table->string('employee_source', 30)->nullable()->after('derived_note');
        });

        DB::table('hc_cost_types')->where('code', 'GAJI.DASAR')->update([
            'employee_source' => 'base_salary',
            'is_derived' => true,
            'derived_note' => 'Total Gaji Dasar seluruh pegawai per unit — dikelola di menu Pegawai & Biaya',
        ]);
    }

    public function down(): void
    {
        DB::table('hc_cost_types')->where('code', 'GAJI.DASAR')->update([
            'is_derived' => false,
            'derived_note' => null,
        ]);

        Schema::table('hc_cost_types', function (Blueprint $table) {
            $table->dropColumn('employee_source');
        });
    }
};
