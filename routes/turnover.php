<?php

use App\Http\Controllers\Turnover\TurnoverController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modul Turnover Pegawai — ekosistem Ethoz (HCIS)
|--------------------------------------------------------------------------
| Monitoring arus masuk-keluar pegawai: tren, tingkat turnover, alasan,
| masa kerja & retensi. Wajib login dan hanya bisa diakses peran dengan hak
| akses modul "turnover" (RBAC).
*/

Route::prefix('turnover')->name('turnover.')->middleware(['ethoz.auth', 'ethoz.module:turnover'])->group(function () {
    Route::get('/', [TurnoverController::class, 'index'])->name('dashboard');
    Route::post('/kejadian', [TurnoverController::class, 'store'])->name('store');
    Route::put('/kejadian/{event}', [TurnoverController::class, 'update'])->name('update');
    Route::delete('/kejadian/{event}', [TurnoverController::class, 'destroy'])->name('destroy');
});
