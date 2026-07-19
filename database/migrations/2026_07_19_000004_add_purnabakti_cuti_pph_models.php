<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Model perhitungan dari workbook BDP RKAP:
 *  - Biaya Purnabakti (sheet "Biaya Purnabakti"): pesangon + UPMK diamortisasi
 *    ke sisa masa kerja; butuh tanggal lahir pegawai.
 *  - Tunjangan Cuti: kriteria Bulan & Hak Cuti (3thn → THP×2, thn → THP×1).
 *  - PPh 21 berbasis TER (sheet "TER"); butuh status PTKP pegawai.
 * Ketiga jenis biaya terkait menjadi bersumber model (per unit, per bulan)
 * dan terkunci di Input Nominal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hc_employees', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('join_date');
            $table->string('ptkp_status', 6)->nullable()->after('birth_date'); // TK/0..K/3
            $table->unsignedTinyInteger('cuti_month')->nullable()->after('ptkp_status'); // 1-12
            $table->string('cuti_entitlement', 6)->nullable()->after('cuti_month'); // thn | 3thn
        });

        // asumsi model purnabakti per tahun anggaran
        $now = now();
        $assumptions = [
            ['code' => 'usia_pensiun', 'label' => 'Usia pensiun pegawai tetap (tahun)', 'category' => 'lainnya', 'value_type' => 'nominal', 'value' => 55, 'applies_to' => 'tetap'],
            ['code' => 'purnabakti_growth', 'label' => 'Proyeksi kenaikan gaji purnabakti (% per tahun)', 'category' => 'lainnya', 'value_type' => 'persen', 'value' => 7, 'applies_to' => 'tetap'],
        ];
        foreach (DB::table('hc_fiscal_years')->pluck('id') as $yearId) {
            foreach ($assumptions as $a) {
                $exists = DB::table('hc_assumptions')
                    ->where('fiscal_year_id', $yearId)->where('code', $a['code'])->exists();
                if (! $exists) {
                    DB::table('hc_assumptions')->insert([
                        ...$a, 'fiscal_year_id' => $yearId,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        // jenis biaya bersumber model perhitungan (per unit per bulan)
        $links = [
            'LAIN.PURNABAKTI' => ['model:purnabakti', 'Total Biaya Purnabakti per unit — model pesangon+UPMK (submenu Input Nominal), amortisasi sisa masa kerja'],
            'TUNJ.CUTI' => ['model:cuti', 'Total Tunjangan Cuti per unit — THP × hak cuti (3 THN = ×2, THN = ×1) pada bulan cuti masing-masing pegawai'],
            'TUNJ.PPH21' => ['model:pph', 'Total PPh 21 per unit — tarif efektif rata-rata (TER) × penghasilan bruto bulanan seluruh pegawai'],
        ];
        foreach ($links as $code => [$source, $note]) {
            DB::table('hc_cost_types')->where('code', $code)->update([
                'employee_source' => $source,
                'is_derived' => true,
                'derived_note' => $note,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('hc_cost_types')->where('code', 'LAIN.PURNABAKTI')->update([
            'employee_source' => null, 'is_derived' => false, 'derived_note' => null,
        ]);
        DB::table('hc_cost_types')->where('code', 'TUNJ.CUTI')->update([
            'employee_source' => null, 'is_derived' => false, 'derived_note' => null,
        ]);
        DB::table('hc_cost_types')->where('code', 'TUNJ.PPH21')->update([
            'employee_source' => 'pph21',
            'derived_note' => 'Total PPh 21 seluruh pegawai per unit (tarif × THP) — dikelola di menu Pegawai & Biaya',
        ]);
        DB::table('hc_assumptions')->whereIn('code', ['usia_pensiun', 'purnabakti_growth'])->delete();

        Schema::table('hc_employees', function (Blueprint $table) {
            $table->dropColumn(['birth_date', 'ptkp_status', 'cuti_month', 'cuti_entitlement']);
        });
    }
};
