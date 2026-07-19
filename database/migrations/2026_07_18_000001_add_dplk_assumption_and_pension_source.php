<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DPLK (iuran dana pensiun pegawai tetap): tarif asumsi per tahun (default
 * 21% dari Gaji /bln), dan Iuran Dana Pensiun menjadi jenis biaya bersumber
 * pegawai — nilainya = grand total DPLK /bln pegawai tetap per unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (DB::table('hc_fiscal_years')->pluck('id') as $yearId) {
            $exists = DB::table('hc_assumptions')
                ->where('fiscal_year_id', $yearId)->where('code', 'dplk')->exists();
            if (! $exists) {
                DB::table('hc_assumptions')->insert([
                    'fiscal_year_id' => $yearId,
                    'code' => 'dplk',
                    'label' => 'Iuran DPLK (% dari gaji dasar)',
                    'category' => 'iuran',
                    'value_type' => 'persen',
                    'value' => 21,
                    'applies_to' => 'tetap',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        DB::table('hc_cost_types')->where('code', 'IURAN.PENSIUN')->update([
            'employee_source' => 'dplk',
            'is_derived' => true,
            'derived_note' => 'Total DPLK (tarif asumsi × Gaji /bln) pegawai tetap per unit — dikelola di menu Pegawai & Biaya',
        ]);
    }

    public function down(): void
    {
        DB::table('hc_assumptions')->where('code', 'dplk')->delete();
        DB::table('hc_cost_types')->where('code', 'IURAN.PENSIUN')->update([
            'employee_source' => null,
            'derived_note' => 'Iuran Dana Pensiun = tarif iuran (asumsi) × Gaji Dasar',
        ]);
    }
};
