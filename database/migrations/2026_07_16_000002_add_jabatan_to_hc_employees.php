<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Jabatan (nama posisi/pekerjaan) pegawai — tampil di menu Pegawai & Biaya dan Grading. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hc_employees', function (Blueprint $table) {
            $table->string('jabatan', 150)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('hc_employees', function (Blueprint $table) {
            $table->dropColumn('jabatan');
        });
    }
};
