<?php

namespace App\Http\Controllers\Ethoz;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Landing Ethoz (HCIS) — terbuka untuk semua pengunjung dan menampilkan
 * seluruh modul ekosistem sebagai katalog kartu. Login baru diwajibkan saat
 * modul dibuka; hak akses peran (RBAC) dicek oleh middleware modulnya.
 */
class HomeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return Inertia::render('Ethoz/Home', [
            'modules' => collect(config('ethoz.modules'))
                ->map(fn ($m, $key) => [
                    'key' => $key,
                    'name' => $m['name'],
                    'description' => $m['description'],
                    'icon' => $m['icon'],
                    'color' => $m['color'],
                    'href' => route($m['entry']),
                    // null = belum login (akses diketahui setelah masuk);
                    // true/false = hak akses peran pengguna saat ini
                    'accessible' => $user?->canAccessModule($key),
                ])->values(),
        ]);
    }
}
