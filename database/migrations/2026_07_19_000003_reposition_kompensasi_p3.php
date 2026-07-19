<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pindahkan Biaya Tunjangan Kompensasi Pihak Ke-3 agar tampil tepat setelah
 * Biaya Tunjangan Kompensasi (kontrak) di seluruh menu.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kompensasiSort = DB::table('hc_cost_types')
            ->where('code', 'TUNJ.KOMPENSASI')->value('sort_order');
        if ($kompensasiSort === null) {
            return;
        }

        // beri ruang satu posisi setelah Kompensasi, lalu tempatkan P3 di sana
        DB::table('hc_cost_types')
            ->where('sort_order', '>', $kompensasiSort)
            ->where('code', '!=', 'TUNJ.KOMPENSASI_P3')
            ->increment('sort_order');

        DB::table('hc_cost_types')->where('code', 'TUNJ.KOMPENSASI_P3')
            ->update(['sort_order' => $kompensasiSort + 1]);
    }

    public function down(): void
    {
        $kompensasiSort = DB::table('hc_cost_types')
            ->where('code', 'TUNJ.KOMPENSASI')->value('sort_order');
        if ($kompensasiSort === null) {
            return;
        }

        DB::table('hc_cost_types')->where('code', 'TUNJ.KOMPENSASI_P3')
            ->update(['sort_order' => (int) DB::table('hc_cost_types')->max('sort_order') + 1]);

        DB::table('hc_cost_types')
            ->where('sort_order', '>', $kompensasiSort)
            ->where('code', '!=', 'TUNJ.KOMPENSASI_P3')
            ->decrement('sort_order');
    }
};
