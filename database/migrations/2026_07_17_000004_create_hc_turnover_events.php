<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Turnover Pegawai: kejadian masuk/keluar pegawai. Data pegawai
 * di-snapshot (nama, unit, status, jabatan, TMT) agar riwayat & metrik tetap
 * utuh walau data roster berubah/terhapus. Headcount historis direkonstruksi
 * dari kejadian: headcount(t) = aktif saat ini − masuk sesudah t + keluar
 * sesudah t — karena itu pegawai aktif existing dibekali kejadian 'masuk'
 * dari TMT-nya (backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hc_turnover_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()
                ->constrained('hc_employees')->nullOnDelete();
            $table->string('employee_name', 150);
            $table->foreignId('work_unit_id')->nullable()
                ->constrained('hc_work_units')->nullOnDelete();
            $table->string('employee_status', 20)->nullable(); // tetap|kontrak|honor|direksi
            $table->string('jabatan', 150)->nullable();
            $table->enum('type', ['masuk', 'keluar']);
            $table->date('event_date')->index();
            $table->string('reason', 30)->nullable();   // keluar: resign|phk|kontrak_habis|pensiun|meninggal|lainnya
            $table->string('category', 20)->nullable(); // keluar: sukarela|tidak_sukarela|lainnya
            $table->date('join_date')->nullable();      // snapshot TMT — dasar hitung masa kerja
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
        });

        // backfill kejadian 'masuk' pegawai aktif dari TMT-nya
        $now = now();
        $rows = DB::table('hc_employees')
            ->where('is_active', true)->whereNotNull('join_date')
            ->get(['id', 'name', 'work_unit_id', 'status', 'jabatan', 'join_date'])
            ->map(fn ($e) => [
                'employee_id' => $e->id,
                'employee_name' => $e->name,
                'work_unit_id' => $e->work_unit_id,
                'employee_status' => $e->status,
                'jabatan' => $e->jabatan,
                'type' => 'masuk',
                'event_date' => $e->join_date,
                'join_date' => $e->join_date,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        foreach ($rows->chunk(500) as $chunk) {
            DB::table('hc_turnover_events')->insert($chunk->values()->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hc_turnover_events');
    }
};
