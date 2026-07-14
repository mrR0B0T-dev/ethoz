<?php

use App\Http\Controllers\HcRkap\AssumptionController;
use App\Http\Controllers\HcRkap\DashboardController;
use App\Http\Controllers\HcRkap\DetailController;
use App\Http\Controllers\HcRkap\EmployeeController;
use App\Http\Controllers\HcRkap\MasterController;
use App\Http\Controllers\HcRkap\NominalController;
use App\Http\Controllers\HcRkap\RealizationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sistem Informasi RKAP HC — Divisi Human Capital & Corporate Secretary
|--------------------------------------------------------------------------
| Monitoring Rencana Kerja dan Anggaran Perusahaan (biaya personil) tahunan.
| Menggunakan sesi login admin yang sama dengan panel admin.
*/

Route::prefix('hc-rkap')->name('hc.')->middleware('admin.auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/detail', [DetailController::class, 'index'])->name('detail');
    Route::put('/detail', [DetailController::class, 'upsert'])->name('detail.upsert');

    Route::get('/nominal', [NominalController::class, 'index'])->name('nominal');
    Route::put('/nominal', [NominalController::class, 'upsert'])->name('nominal.upsert');

    Route::get('/realisasi', [RealizationController::class, 'index'])->name('realisasi');
    Route::post('/realisasi', [RealizationController::class, 'store'])->name('realisasi.store');
    Route::delete('/realisasi', [RealizationController::class, 'destroy'])->name('realisasi.destroy');
    Route::get('/realisasi/template', [RealizationController::class, 'template'])->name('realisasi.template');
    Route::post('/realisasi/import', [RealizationController::class, 'import'])->name('realisasi.import');

    Route::get('/pegawai', [EmployeeController::class, 'index'])->name('pegawai');
    Route::post('/pegawai', [EmployeeController::class, 'store'])->name('pegawai.store');
    Route::put('/pegawai/{employee}', [EmployeeController::class, 'update'])->name('pegawai.update');
    Route::delete('/pegawai/{employee}', [EmployeeController::class, 'destroy'])->name('pegawai.destroy');

    Route::get('/asumsi', [AssumptionController::class, 'index'])->name('asumsi');
    Route::post('/asumsi', [AssumptionController::class, 'store'])->name('asumsi.store');
    Route::put('/asumsi/{assumption}', [AssumptionController::class, 'update'])->name('asumsi.update');
    Route::delete('/asumsi/{assumption}', [AssumptionController::class, 'destroy'])->name('asumsi.destroy');

    Route::get('/master', [MasterController::class, 'index'])->name('master');
    Route::post('/master/tahun', [MasterController::class, 'storeYear'])->name('master.tahun.store');
    Route::put('/master/tahun/{fiscalYear}', [MasterController::class, 'updateYear'])->name('master.tahun.update');
    Route::delete('/master/tahun/{fiscalYear}', [MasterController::class, 'destroyYear'])->name('master.tahun.destroy');
    Route::post('/master/unit', [MasterController::class, 'storeUnit'])->name('master.unit.store');
    Route::put('/master/unit/{workUnit}', [MasterController::class, 'updateUnit'])->name('master.unit.update');
    Route::delete('/master/unit/{workUnit}', [MasterController::class, 'destroyUnit'])->name('master.unit.destroy');
    Route::post('/master/jenis-biaya', [MasterController::class, 'storeCostType'])->name('master.biaya.store');
    Route::put('/master/jenis-biaya/{costType}', [MasterController::class, 'updateCostType'])->name('master.biaya.update');
    Route::delete('/master/jenis-biaya/{costType}', [MasterController::class, 'destroyCostType'])->name('master.biaya.destroy');
});
