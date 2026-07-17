<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gaji Tahun Sebelumnya per pegawai. Gaji /bln (base_salary) dihitung dari
 * nilai ini + asumsi kenaikan gaji per status pada tahun anggaran aktif:
 * base = prev × (1 + kenaikan%/100).
 *
 * Backfill: prev = base ÷ faktor kenaikan, sehingga gaji berjalan TIDAK
 * berubah oleh migrasi ini (anggaran & grading tetap konsisten).
 */
return new class extends Migration
{
    private const CODE_BY_STATUS = [
        'tetap' => 'kenaikan_tetap',
        'kontrak' => 'kenaikan_kontrak',
        'honor' => 'kenaikan_ump',
        'direksi' => 'kenaikan_dirkom',
    ];

    public function up(): void
    {
        Schema::table('hc_employees', function (Blueprint $table) {
            $table->decimal('prev_year_salary', 16, 2)->nullable()->after('base_salary');
        });

        $activeYearId = DB::table('hc_fiscal_years')->where('status', 'aktif')->value('id');
        if (! $activeYearId) {
            return; // tanpa tahun aktif, prev dibiarkan kosong
        }

        foreach (self::CODE_BY_STATUS as $status => $code) {
            $pct = (float) DB::table('hc_assumptions')
                ->where('fiscal_year_id', $activeYearId)
                ->where('code', $code)
                ->value('value');
            $factor = 1 + $pct / 100;
            if ($factor <= 0) {
                continue;
            }
            DB::table('hc_employees')->where('status', $status)
                ->update(['prev_year_salary' => DB::raw('ROUND(base_salary / '.$factor.', 2)')]);
        }
    }

    public function down(): void
    {
        Schema::table('hc_employees', function (Blueprint $table) {
            $table->dropColumn('prev_year_salary');
        });
    }
};
