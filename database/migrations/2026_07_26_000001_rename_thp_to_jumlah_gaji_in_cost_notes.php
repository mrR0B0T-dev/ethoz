<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Selaraskan istilah pada data yang sudah tersimpan:
 *  1. Ganti istilah "THP" → "Jumlah Gaji" di keterangan jenis biaya turunan.
 *  2. Dasar iuran BPJS Kes & BPJS TK: "gaji pokok" → "Jumlah Gaji" (perubahan
 *     multiplier mengikuti Jumlah Gaji = gaji pokok + tunjangan).
 * Bersifat kosmetik/keterangan; nominal dihitung ulang oleh EmployeeCostService.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) istilah THP → Jumlah Gaji pada seluruh keterangan turunan
        DB::table('hc_cost_types')
            ->where('derived_note', 'like', '%THP%')
            ->get(['id', 'derived_note'])
            ->each(fn ($row) => DB::table('hc_cost_types')->where('id', $row->id)->update([
                'derived_note' => str_replace('THP', 'Jumlah Gaji', $row->derived_note),
            ]));

        // 2) dasar iuran BPJS Kes & TK: gaji pokok → Jumlah Gaji
        DB::table('hc_cost_types')
            ->whereIn('code', ['IURAN.JAMSOSTEK', 'IURAN.BPJSKES'])
            ->get(['id', 'derived_note'])
            ->each(fn ($row) => DB::table('hc_cost_types')->where('id', $row->id)->update([
                'derived_note' => str_replace('× gaji pokok', '× Jumlah Gaji', (string) $row->derived_note),
            ]));
    }

    public function down(): void
    {
        // Kembalikan istilah semula.
        DB::table('hc_cost_types')
            ->whereIn('code', ['IURAN.JAMSOSTEK', 'IURAN.BPJSKES'])
            ->get(['id', 'derived_note'])
            ->each(fn ($row) => DB::table('hc_cost_types')->where('id', $row->id)->update([
                'derived_note' => str_replace('× Jumlah Gaji', '× gaji pokok', (string) $row->derived_note),
            ]));

        DB::table('hc_cost_types')
            ->where('derived_note', 'like', '%Jumlah Gaji%')
            ->get(['id', 'derived_note'])
            ->each(fn ($row) => DB::table('hc_cost_types')->where('id', $row->id)->update([
                'derived_note' => str_replace('Jumlah Gaji', 'THP', $row->derived_note),
            ]));
    }
};
