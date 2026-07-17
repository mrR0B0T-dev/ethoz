<?php

/*
|--------------------------------------------------------------------------
| Ethoz — Human Capital Information System (HCIS)
|--------------------------------------------------------------------------
| Registri modul ekosistem. Menambahkan modul HR baru cukup:
|   1. daftarkan modulnya di sini dengan key unik,
|   2. bungkus seluruh route modul dengan middleware `ethoz.module:<key>`,
|   3. berikan akses modul ke peran terkait lewat modul Administrasi.
| Portal dan RBAC otomatis mengikuti registri ini.
*/

return [

    'name' => 'Ethoz',
    'tagline' => 'Human Capital Information System',

    'modules' => [

        'rkap' => [
            'name' => 'RKAP HC',
            'description' => 'Monitoring Rencana Kerja & Anggaran Perusahaan — perencanaan dan realisasi biaya personil.',
            'entry' => 'hc.dashboard', // nama route halaman awal modul
            'icon' => 'chart',         // key ikon kartu portal (dipetakan di frontend)
            'color' => '#2a78d6',
        ],

        'turnover' => [
            'name' => 'Turnover Pegawai',
            'description' => 'Pantau arus masuk-keluar pegawai: tren bulanan, tingkat turnover, alasan keluar, masa kerja, dan retensi.',
            'entry' => 'turnover.dashboard',
            'icon' => 'exchange',
            'color' => '#0d9488',
        ],

        'admin' => [
            'name' => 'Administrasi',
            'description' => 'Kelola pengguna, peran, dan hak akses modul ekosistem Ethoz.',
            'entry' => 'ethoz.admin',
            'icon' => 'shield',
            'color' => '#7c3aed',
        ],

    ],
];
