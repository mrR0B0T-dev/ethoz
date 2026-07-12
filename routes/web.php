<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/hc-rkap');

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

// ── Sistem Informasi RKAP HC (Human Capital & Corporate Secretary) ──────────
require __DIR__ . "/hc_rkap.php";
