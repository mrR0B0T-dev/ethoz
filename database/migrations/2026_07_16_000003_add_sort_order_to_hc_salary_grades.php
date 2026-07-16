<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Urutan tampilan baris struktur skala upah (bisa diatur dari menu Grading). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hc_salary_grades', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('level');
        });

        DB::statement('UPDATE hc_salary_grades SET sort_order = level');
    }

    public function down(): void
    {
        Schema::table('hc_salary_grades', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
