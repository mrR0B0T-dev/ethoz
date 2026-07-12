<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Akun admin bawaan untuk masuk ke Sistem Informasi RKAP HC.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@rkap-hc.test'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('admin123'),
            ],
        );
    }
}
