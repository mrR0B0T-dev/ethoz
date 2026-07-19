<?php

namespace Database\Seeders;

use App\Models\EthozRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Akun & peran bawaan ekosistem Ethoz:
 *  - Super Admin  : akses seluruh modul ('*').
 *  - Services     : hanya modul RKAP HC.
 *  - Development  : hanya modul Turnover Pegawai.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'super-admin' => [
                'label' => 'Super Admin',
                'description' => 'Administrator — akses penuh ke seluruh modul ekosistem Ethoz.',
                'modules' => ['*'],
                'user' => ['name' => 'Administrator', 'email' => 'admin@hc.test', 'password' => 'admin123'],
            ],
            'services' => [
                'label' => 'Services',
                'description' => 'Akses modul RKAP HC (perencanaan & realisasi biaya personil).',
                'modules' => ['rkap'],
                'user' => ['name' => 'Services', 'email' => 'services@hc.test', 'password' => 'services123'],
            ],
            'development' => [
                'label' => 'Development',
                'description' => 'Akses modul Turnover Pegawai (monitoring arus masuk-keluar pegawai).',
                'modules' => ['turnover'],
                'user' => ['name' => 'Development', 'email' => 'development@hc.test', 'password' => 'development123'],
            ],
        ];

        foreach ($roles as $name => $def) {
            $role = EthozRole::updateOrCreate(
                ['name' => $name],
                ['label' => $def['label'], 'description' => $def['description']],
            );
            $role->syncModules($def['modules']);

            $user = User::updateOrCreate(
                ['email' => $def['user']['email']],
                [
                    'name' => $def['user']['name'],
                    'password' => Hash::make($def['user']['password']),
                ],
            );
            $user->ethozRoles()->sync([$role->id]);
        }
    }
}
