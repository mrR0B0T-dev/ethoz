<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log aktivitas ekosistem Ethoz (HCIS): jejak audit seluruh operasi CRUD
 * (buat / ubah / hapus) yang dilakukan pengguna pada entitas aplikasi.
 * Bersifat append-only — hanya dapat dilihat (Super Admin), tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ethoz_activity_logs', function (Blueprint $table) {
            $table->id();
            // pelaku: FK ke users di-null-kan bila pengguna dihapus, tetapi
            // nama disimpan sebagai snapshot agar riwayat tetap terbaca
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('action', 20);              // created | updated | deleted
            $table->string('module', 60)->nullable();  // key modul (rkap/turnover/admin)
            $table->string('subject_type')->nullable();// nama singkat model, mis. "User"
            $table->string('subject_id')->nullable();  // id entitas terkait
            $table->string('subject_label')->nullable();// label terbaca entitas
            $table->string('description')->nullable();  // ringkasan aktivitas
            $table->json('properties')->nullable();     // perubahan atribut (old/new)
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index('user_id');
            $table->index('action');
            $table->index('module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ethoz_activity_logs');
    }
};
