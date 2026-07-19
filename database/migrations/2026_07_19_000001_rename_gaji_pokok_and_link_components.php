<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1) Ganti istilah "Gaji Dasar" menjadi "Gaji Pokok" pada nama jenis biaya,
 *    keterangan referensi, dan label asumsi.
 * 2) Tautkan komponen biaya yang juga tampil di Pegawai & Biaya sebagai
 *    jenis biaya bersumber pegawai: nilai RKAP per unit = grand total
 *    komponen tersebut dari menu Pegawai & Biaya (tahunan ÷ 12 per bulan),
 *    sehingga terkunci di Input Nominal.
 */
return new class extends Migration
{
    /** code jenis biaya => [employee_source, keterangan] */
    private array $links = [
        'TUNJ.THR' => ['thr', 'Total THR seluruh pegawai per unit (asumsi bulan THR × THP) — dikelola di menu Pegawai & Biaya'],
        'TUNJ.BONUS' => ['bonus', 'Total Bonus seluruh pegawai per unit (asumsi bulan bonus × THP) — dikelola di menu Pegawai & Biaya'],
        'TUNJ.KOMPENSASI' => ['kompensasi', 'Total Kompensasi seluruh pegawai per unit (asumsi bulan × gaji pokok) — dikelola di menu Pegawai & Biaya'],
        'TUNJ.PPH21' => ['pph21', 'Total PPh 21 seluruh pegawai per unit (tarif × THP) — dikelola di menu Pegawai & Biaya'],
        'IURAN.JAMSOSTEK' => ['bpjs_tk', 'Total BPJS TK seluruh pegawai per unit (tarif iuran × gaji pokok) — dikelola di menu Pegawai & Biaya'],
        'IURAN.BPJSKES' => ['bpjs_kes', 'Total BPJS Kes seluruh pegawai per unit (tarif iuran × gaji pokok) — dikelola di menu Pegawai & Biaya'],
    ];

    public function up(): void
    {
        DB::table('hc_cost_types')->where('code', 'GAJI.DASAR')->update([
            'name' => 'Biaya Gaji Pokok',
            'derived_note' => 'Total Gaji Pokok seluruh pegawai per unit — dikelola di menu Pegawai & Biaya',
        ]);
        DB::table('hc_cost_types')->where('code', 'IURAN.PENSIUN')->update([
            'derived_note' => 'Total DPLK (tarif asumsi × Gaji Pokok /bln) pegawai tetap per unit — dikelola di menu Pegawai & Biaya',
        ]);
        DB::table('hc_assumptions')->where('code', 'dplk')
            ->update(['label' => 'Iuran DPLK (% dari gaji pokok)']);

        foreach ($this->links as $code => [$source, $note]) {
            DB::table('hc_cost_types')->where('code', $code)->update([
                'employee_source' => $source,
                'is_derived' => true,
                'derived_note' => $note,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('hc_cost_types')->where('code', 'GAJI.DASAR')->update([
            'name' => 'Biaya Gaji Dasar',
            'derived_note' => 'Total Gaji Dasar seluruh pegawai per unit — dikelola di menu Pegawai & Biaya',
        ]);
        DB::table('hc_assumptions')->where('code', 'dplk')
            ->update(['label' => 'Iuran DPLK (% dari gaji dasar)']);

        foreach (array_keys($this->links) as $code) {
            DB::table('hc_cost_types')->where('code', $code)
                ->update(['employee_source' => null]);
        }
    }
};
