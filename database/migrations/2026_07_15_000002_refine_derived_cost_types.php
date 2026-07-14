<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sempurnakan penandaan jenis biaya "turunan": nilainya mengikuti aturan
 * berbasis Gaji Dasar / Tunj. Jabatan / Tunj. Transport sehingga tidak
 * diinput manual di menu Input Nominal. Termasuk menambahkan Iuran Pensiun
 * (persentase dari Gaji Dasar) dan memperjelas keterangan referensinya.
 */
return new class extends Migration
{
    /** code => keterangan referensi (menyebut komponen basis secara eksplisit) */
    private array $derived = [
        'TUNJ.THR' => 'THR = jumlah bulan THR (asumsi) × (Gaji Dasar + Tunj. Jabatan + Tunj. Transport)',
        'TUNJ.BONUS' => 'Bonus = jumlah bulan bonus (asumsi) × (Gaji Dasar + Tunj. Jabatan + Tunj. Transport)',
        'TUNJ.PPH21' => 'PPh 21 dihitung dari Gaji Dasar, Tunj. Jabatan & Tunj. Transport',
        'TUNJ.KOMPENSASI' => 'Kompensasi = jumlah bulan (asumsi) × Gaji Dasar',
        'IURAN.JAMSOSTEK' => 'BPJS Ketenagakerjaan = tarif iuran (asumsi) × Gaji Dasar',
        'IURAN.BPJSKES' => 'BPJS Kesehatan = tarif iuran (asumsi) × Gaji Dasar',
        'IURAN.PENSIUN' => 'Iuran Dana Pensiun = tarif iuran (asumsi) × Gaji Dasar',
    ];

    public function up(): void
    {
        foreach ($this->derived as $code => $note) {
            DB::table('hc_cost_types')->where('code', $code)
                ->update(['is_derived' => true, 'derived_note' => $note]);
        }
    }

    public function down(): void
    {
        // kembalikan Iuran Pensiun ke non-turunan; sisanya tetap turunan
        DB::table('hc_cost_types')->where('code', 'IURAN.PENSIUN')
            ->update(['is_derived' => false, 'derived_note' => null]);
    }
};
