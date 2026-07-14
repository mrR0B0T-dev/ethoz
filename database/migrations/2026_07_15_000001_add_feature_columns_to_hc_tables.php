<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hc_employees', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('join_date');
        });

        Schema::table('hc_cost_types', function (Blueprint $table) {
            // nominal mengacu/terhitung dari jenis biaya lain → tidak diinput manual
            $table->boolean('is_derived')->default(false)->after('employee_status');
            $table->string('derived_note', 200)->nullable()->after('is_derived');
            // multi status: disimpan sebagai daftar dipisah koma (mis. "tetap,kontrak")
            $table->string('employee_status', 60)->nullable()->change();
        });

        Schema::table('hc_assumptions', function (Blueprint $table) {
            $table->string('applies_to', 60)->nullable()->change();
        });

        // tandai jenis biaya yang nilainya mengikuti jenis biaya lain
        $derived = [
            'TUNJ.THR' => 'THR = asumsi bulan THR × Biaya Gaji',
            'TUNJ.BONUS' => 'Bonus = asumsi bulan bonus × THP (gaji + tunjangan)',
            'TUNJ.PPH21' => 'Mengikuti Biaya Gaji & tarif PPh 21',
            'TUNJ.KOMPENSASI' => 'Kompensasi = asumsi bulan kompensasi × gaji kontrak',
            'IURAN.JAMSOSTEK' => 'Persentase BPJS TK (asumsi) × Biaya Gaji',
            'IURAN.BPJSKES' => 'Persentase BPJS Kesehatan (asumsi) × Biaya Gaji',
        ];
        foreach ($derived as $code => $note) {
            DB::table('hc_cost_types')->where('code', $code)
                ->update(['is_derived' => true, 'derived_note' => $note]);
        }
    }

    public function down(): void
    {
        Schema::table('hc_employees', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('hc_cost_types', function (Blueprint $table) {
            $table->dropColumn(['is_derived', 'derived_note']);
            $table->string('employee_status', 20)->nullable()->change();
        });

        Schema::table('hc_assumptions', function (Blueprint $table) {
            $table->string('applies_to', 20)->nullable()->change();
        });
    }
};
