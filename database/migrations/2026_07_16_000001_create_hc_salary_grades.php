<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master grading & struktur skala upah: 17 grade (A-1 s.d. F-2) dengan
 * rentang gaji dasar (min/mid/max), tunjangan jabatan, dan tunjangan
 * transportasi per grade — sumber: struktur gaji existing perusahaan.
 * Pegawai mendapat kolom salary_grade_id (hasil grading) + grade_source
 * (auto = disimpulkan sistem, manual = ditetapkan pengguna).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hc_salary_grades', function (Blueprint $table) {
            $table->id();
            $table->string('jabatan', 40);
            $table->string('code', 8)->unique();
            $table->unsignedTinyInteger('level')->unique();
            $table->decimal('salary_min', 16, 2);
            $table->decimal('salary_mid', 16, 2);
            $table->decimal('salary_max', 16, 2);
            $table->decimal('position_allowance', 16, 2)->default(0);
            $table->decimal('transport_allowance', 16, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('hc_employees', function (Blueprint $table) {
            $table->foreignId('salary_grade_id')->nullable()->after('status')
                ->constrained('hc_salary_grades')->nullOnDelete();
            $table->string('grade_source', 10)->nullable()->after('salary_grade_id');
        });

        $now = now();
        // [jabatan, code, level, min, mid, max, tunj_jabatan, tunj_transport]
        $rows = [
            ['Non-Staff', 'A-1', 1, 2200512, 4572160, 5943808, 457216, 1650000],
            ['Non-Staff', 'A-2', 2, 2520563, 5029376, 6538188, 502938, 1650000],
            ['Staff', 'B-1', 3, 2766157, 5532313, 8298470, 553231, 1650000],
            ['Staff', 'B-2', 4, 3042772, 6085544, 9128317, 608554, 1650000],
            ['Staff', 'B-3', 5, 3347049, 6694099, 10041148, 669410, 1650000],
            ['Staff', 'B-4', 6, 3681754, 7363509, 11045263, 736351, 1650000],
            ['Section Head', 'C-1', 7, 4123565, 8247130, 12370695, 1237069, 2618000],
            ['Section Head', 'C-2', 8, 4535921, 9071843, 13607764, 1360776, 2618000],
            ['Section Head', 'C-3', 9, 4989514, 9979027, 14968541, 1496854, 2618000],
            ['Section Head', 'C-4', 10, 5488465, 10976930, 16465395, 1646539, 2618000],
            ['Department Head', 'D-1', 11, 5049388, 12623469, 20197551, 2524694, 2865500],
            ['Department Head', 'D-2', 12, 5554327, 13885816, 22217306, 2777163, 2865500],
            ['Department Head', 'D-3', 13, 6109759, 15274398, 24439037, 3054880, 2865500],
            ['Group Head', 'E-1', 14, 7331711, 18329278, 29326844, 5498783, 3597000],
            ['Group Head', 'E-2', 15, 8064882, 20162205, 32259528, 6048662, 3597000],
            ['SEVP', 'F-1', 16, 10484347, 26210867, 41937387, 10484347, 4250400],
            ['SEVP', 'F-2', 17, 11532781, 28831954, 46131126, 11532781, 4250400],
        ];

        DB::table('hc_salary_grades')->insert(array_map(fn ($r) => [
            'jabatan' => $r[0],
            'code' => $r[1],
            'level' => $r[2],
            'salary_min' => $r[3],
            'salary_mid' => $r[4],
            'salary_max' => $r[5],
            'position_allowance' => $r[6],
            'transport_allowance' => $r[7],
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows));
    }

    public function down(): void
    {
        Schema::table('hc_employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salary_grade_id');
            $table->dropColumn('grade_source');
        });
        Schema::dropIfExists('hc_salary_grades');
    }
};
