<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jadikan Biaya Transport & Biaya Tunjangan Jabatan bersumber dari data
 * pegawai — sama seperti Biaya Gaji Dasar: nilainya = total Tunj. Transport /
 * Tunj. Jabatan seluruh pegawai per unit (disebar 12 bulan), dikelola di menu
 * Pegawai & Biaya dan tidak diinput manual.
 *
 * Sinkronisasi nilai dijalankan terpisah setelah migrasi (EmployeeCostService).
 * Biaya Gaji GMM & Biaya Gaji Cabang TIDAK diubah: keduanya melekat pada unit
 * (JOINT / CABANG) yang tidak memiliki pegawai sehingga tetap sebagai baris
 * terkunci bernilai tetap.
 */
return new class extends Migration
{
    private array $sourced = [
        'GAJI.TRANSPORT' => [
            'employee_source' => 'transport_allowance',
            'note' => 'Total Tunj. Transport seluruh pegawai per unit — dikelola di menu Pegawai & Biaya',
        ],
        'GAJI.JABATAN' => [
            'employee_source' => 'position_allowance',
            'note' => 'Total Tunj. Jabatan seluruh pegawai per unit — dikelola di menu Pegawai & Biaya',
        ],
    ];

    public function up(): void
    {
        foreach ($this->sourced as $code => $cfg) {
            DB::table('hc_cost_types')->where('code', $code)->update([
                'is_derived' => true,
                'employee_source' => $cfg['employee_source'],
                'derived_note' => $cfg['note'],
            ]);
        }
    }

    public function down(): void
    {
        // kembalikan menjadi terkunci-tanpa-sumber (kondisi migrasi 000004)
        $locked = [
            'GAJI.TRANSPORT' => 'Nilai Biaya Transport terkunci mengikuti kebijakan biaya personil dan tidak diinput manual di menu Input Nominal.',
            'GAJI.JABATAN' => 'Nilai Biaya Tunjangan Jabatan terkunci mengikuti kebijakan biaya personil dan tidak diinput manual di menu Input Nominal.',
        ];
        foreach ($locked as $code => $note) {
            DB::table('hc_cost_types')->where('code', $code)
                ->update(['employee_source' => null, 'derived_note' => $note]);
        }
    }
};
