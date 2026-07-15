<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kunci komponen Biaya Gaji lain (Transport, Tunj. Jabatan, Gaji GMM,
 * Gaji Cabang) agar tidak dapat diinput manual di menu Input Nominal —
 * perlakuan sama seperti Biaya Gaji Dasar. Nilai RKAP yang sudah ada
 * TIDAK diubah; komponen hanya menjadi baca-saja (is_derived = true).
 */
return new class extends Migration
{
    /** code => keterangan yang tampil pada komponen terkunci */
    private array $locked = [
        'GAJI.TRANSPORT' => 'Nilai Biaya Transport terkunci mengikuti kebijakan biaya personil dan tidak diinput manual di menu Input Nominal.',
        'GAJI.JABATAN' => 'Nilai Biaya Tunjangan Jabatan terkunci mengikuti kebijakan biaya personil dan tidak diinput manual di menu Input Nominal.',
        'GAJI.GMM' => 'Nilai Biaya Gaji GMM terkunci mengikuti kebijakan biaya personil dan tidak diinput manual di menu Input Nominal.',
        'GAJI.CABANG' => 'Nilai Biaya Gaji Cabang terkunci mengikuti kebijakan biaya personil dan tidak diinput manual di menu Input Nominal.',
    ];

    public function up(): void
    {
        foreach ($this->locked as $code => $note) {
            DB::table('hc_cost_types')->where('code', $code)
                ->update(['is_derived' => true, 'derived_note' => $note]);
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->locked) as $code) {
            DB::table('hc_cost_types')->where('code', $code)
                ->update(['is_derived' => false, 'derived_note' => null]);
        }
    }
};
