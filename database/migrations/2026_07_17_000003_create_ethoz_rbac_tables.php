<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC ekosistem Ethoz (HCIS): peran, keanggotaan pengguna, dan hak akses
 * modul per peran. Akses modul dicek per key modul (lihat config/ethoz.php);
 * modul '*' berarti seluruh modul (super admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ethoz_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();   // slug, mis. super-admin
            $table->string('label', 100);           // nama tampil, mis. Super Admin
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('ethoz_role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('ethoz_roles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
        });

        Schema::create('ethoz_module_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('ethoz_roles')->cascadeOnDelete();
            $table->string('module', 60); // key modul di config/ethoz.php, atau '*'
            $table->primary(['role_id', 'module']);
        });

        // peran bawaan
        $now = now();
        $superId = DB::table('ethoz_roles')->insertGetId([
            'name' => 'super-admin', 'label' => 'Super Admin',
            'description' => 'Akses penuh ke seluruh modul ekosistem Ethoz.',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $rkapId = DB::table('ethoz_roles')->insertGetId([
            'name' => 'pengguna-rkap', 'label' => 'Pengguna RKAP HC',
            'description' => 'Akses modul RKAP HC (perencanaan & realisasi biaya personil).',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('ethoz_module_role')->insert([
            ['role_id' => $superId, 'module' => '*'],
            ['role_id' => $rkapId, 'module' => 'rkap'],
        ]);

        // seluruh pengguna existing menjadi Super Admin agar tidak ada yang
        // terkunci saat RBAC diaktifkan — persempit lewat modul Administrasi
        DB::table('ethoz_role_user')->insert(
            DB::table('users')->pluck('id')
                ->map(fn ($id) => ['role_id' => $superId, 'user_id' => $id])
                ->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ethoz_module_role');
        Schema::dropIfExists('ethoz_role_user');
        Schema::dropIfExists('ethoz_roles');
    }
};
