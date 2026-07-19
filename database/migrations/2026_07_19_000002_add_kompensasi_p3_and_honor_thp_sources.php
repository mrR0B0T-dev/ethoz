<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1) Komponen baru "Biaya Tunjangan Kompensasi Pihak Ke-3": nilainya = grand
 *    total Kompensasi pegawai honor/outsource per unit; Biaya Tunjangan
 *    Kompensasi menjadi khusus Kompensasi pegawai kontrak.
 * 2) Biaya Honorarium Bulanan = grand total THP /bln pegawai kontrak per unit;
 *    Biaya Honorarium Pihak Ke-3 = grand total THP /bln pegawai honor per unit.
 * Semua bersumber pegawai → terkunci di Input Nominal.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $tunjId = DB::table('hc_cost_types')->where('code', 'TUNJ')->value('id');
        $maxSort = (int) DB::table('hc_cost_types')->max('sort_order');

        if (! DB::table('hc_cost_types')->where('code', 'TUNJ.KOMPENSASI_P3')->exists()) {
            DB::table('hc_cost_types')->insert([
                'code' => 'TUNJ.KOMPENSASI_P3',
                'name' => 'Biaya Tunjangan Kompensasi Pihak Ke-3',
                'parent_id' => $tunjId,
                'employee_status' => 'honor',
                'is_derived' => true,
                'derived_note' => 'Total Kompensasi pegawai honor/outsource per unit — dikelola di menu Pegawai & Biaya',
                'employee_source' => 'kompensasi:honor',
                'sort_order' => $maxSort + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('hc_cost_types')->where('code', 'TUNJ.KOMPENSASI')->update([
            'employee_status' => 'kontrak',
            'employee_source' => 'kompensasi:kontrak',
            'is_derived' => true,
            'derived_note' => 'Total Kompensasi pegawai kontrak per unit — dikelola di menu Pegawai & Biaya',
        ]);

        DB::table('hc_cost_types')->where('code', 'HONOR.BULANAN')->update([
            'employee_source' => 'thp:kontrak',
            'is_derived' => true,
            'derived_note' => 'Total THP /bln (gaji pokok + tunjangan) pegawai kontrak per unit — dikelola di menu Pegawai & Biaya',
        ]);

        DB::table('hc_cost_types')->where('code', 'HONOR.PIHAK3')->update([
            'employee_source' => 'thp:honor',
            'is_derived' => true,
            'derived_note' => 'Total THP /bln (gaji pokok + tunjangan) pegawai honor/outsource per unit — dikelola di menu Pegawai & Biaya',
        ]);
    }

    public function down(): void
    {
        DB::table('hc_budget_entries')->whereIn('cost_type_id', function ($q) {
            $q->select('id')->from('hc_cost_types')->where('code', 'TUNJ.KOMPENSASI_P3');
        })->delete();
        DB::table('hc_cost_types')->where('code', 'TUNJ.KOMPENSASI_P3')->delete();

        DB::table('hc_cost_types')->where('code', 'TUNJ.KOMPENSASI')->update([
            'employee_source' => 'kompensasi',
            'derived_note' => 'Total Kompensasi seluruh pegawai per unit (asumsi bulan × gaji pokok) — dikelola di menu Pegawai & Biaya',
        ]);
        DB::table('hc_cost_types')->whereIn('code', ['HONOR.BULANAN', 'HONOR.PIHAK3'])->update([
            'employee_source' => null,
            'is_derived' => false,
            'derived_note' => null,
        ]);
    }
};
