<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Terapkan ulang tautan model perhitungan pada jenis biaya terkait
 * (migrasi 2026_07_19_000004 tidak berefek pada database hasil seed baru
 * karena jenis biaya baru dibuat seeder SETELAH migrasi berjalan, tanpa
 * flag model). Biaya Tunjangan Cuti, Biaya Purnabakti, dan Tunjangan Pajak
 * PPh 21 menjadi bersumber model (per unit per bulan) dan terkunci di
 * Input Nominal. Sinkronisasi nilai dijalankan terpisah setelah migrasi
 * (EmployeeCostService::syncAllOpenYears).
 */
return new class extends Migration
{
    private array $links = [
        'LAIN.PURNABAKTI' => ['model:purnabakti', 'Total Biaya Purnabakti per unit — model pesangon+UPMK (submenu Input Nominal), amortisasi sisa masa kerja'],
        'TUNJ.CUTI' => ['model:cuti', 'Total Tunjangan Cuti per unit — THP × hak cuti (3 THN = ×2, THN = ×1) pada bulan cuti masing-masing pegawai'],
        'TUNJ.PPH21' => ['model:pph', 'Total PPh 21 per unit — tarif efektif rata-rata (TER) × penghasilan bruto bulanan seluruh pegawai'],
    ];

    public function up(): void
    {
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
        DB::table('hc_cost_types')->whereIn('code', ['LAIN.PURNABAKTI', 'TUNJ.CUTI'])->update([
            'employee_source' => null, 'is_derived' => false, 'derived_note' => null,
        ]);
        DB::table('hc_cost_types')->where('code', 'TUNJ.PPH21')->update([
            'employee_source' => 'pph21',
            'derived_note' => 'Total PPh 21 seluruh pegawai per unit (tarif × THP) — dikelola di menu Pegawai & Biaya',
        ]);
    }
};
