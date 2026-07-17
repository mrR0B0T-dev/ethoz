<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Ethoz\AdminController;
use App\Http\Controllers\Ethoz\HomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Ethoz — Human Capital Information System (HCIS)
|--------------------------------------------------------------------------
| Alur akses: (1) landing /ethoz terbuka untuk semua dan menampilkan seluruh
| modul; (2) pengguna memilih modul; (3) saat modul dibuka, login diwajibkan
| (ethoz.auth) lalu hak akses peran dicek (ethoz.module:<key>, RBAC);
| (4) lolos keduanya → seluruh fitur modul terbuka. Registri: config/ethoz.php.
*/

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

// ── Landing ekosistem (publik): katalog seluruh modul ───────────────────────
Route::redirect('/', '/ethoz');
Route::get('/ethoz', [HomeController::class, 'index'])->name('ethoz.home');

// ── Modul Administrasi: pengguna, peran & hak akses modul ───────────────────
Route::prefix('ethoz/admin')->middleware(['ethoz.auth', 'ethoz.module:admin'])->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('ethoz.admin');
    Route::post('/pengguna', [AdminController::class, 'storeUser'])->name('ethoz.admin.users.store');
    Route::put('/pengguna/{user}', [AdminController::class, 'updateUser'])->name('ethoz.admin.users.update');
    Route::delete('/pengguna/{user}', [AdminController::class, 'destroyUser'])->name('ethoz.admin.users.destroy');
    Route::post('/peran', [AdminController::class, 'storeRole'])->name('ethoz.admin.roles.store');
    Route::put('/peran/{role}', [AdminController::class, 'updateRole'])->name('ethoz.admin.roles.update');
    Route::delete('/peran/{role}', [AdminController::class, 'destroyRole'])->name('ethoz.admin.roles.destroy');
});

// ── Modul RKAP HC (Human Capital & Corporate Secretary) ─────────────────────
require __DIR__.'/hc_rkap.php';

// ── Modul Turnover Pegawai ───────────────────────────────────────────────────
require __DIR__.'/turnover.php';
