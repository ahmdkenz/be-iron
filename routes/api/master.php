<?php

use App\Domain\Finance\OpeningBalance\Controllers\OpeningBalanceController;
use App\Domain\Master\Barang\Controllers\BarangController;
use App\Domain\Master\Brand\Controllers\BrandController;
use App\Domain\Master\Investor\Controllers\InvestorController;
use App\Domain\Master\Karyawan\Controllers\KaryawanController;
use App\Domain\Master\Perusahaan\Controllers\PerusahaanController;
use App\Domain\Master\Resto\Controllers\RestoController;
use App\Domain\Master\Unified\Controllers\UnifiedMasterController;
use Illuminate\Support\Facades\Route;

// Must declare named sub-routes BEFORE apiResource binding
Route::middleware('role:ADMIN|MANAGER|SUPERVISOR')->group(function () {
    Route::get('/karyawan/search', [KaryawanController::class, 'search']);
    Route::delete('/karyawan/bulk', [KaryawanController::class, 'bulkDestroy']);
    Route::apiResource('karyawan', KaryawanController::class);
});

Route::get('/investor/export', [InvestorController::class, 'export']);

Route::get('/resto/export', [RestoController::class, 'export']);

Route::get('/barang/external-catalog', [BarangController::class, 'externalCatalog']);

Route::middleware('role:ADMIN|MANAGER|SUPERVISOR')->group(function () {
    Route::get('/master-data/import-template', [UnifiedMasterController::class, 'importTemplate']);
    Route::post('/master-data/import', [UnifiedMasterController::class, 'import']);
    Route::get('/master-data/import/latest', [UnifiedMasterController::class, 'latestImport']);
    Route::get('/master-data/import/{id}/status', [UnifiedMasterController::class, 'importStatus']);

    // Tab "Import Master Opening Balance" — bulk import saldo awal piutang klien (backfill data historis).
    Route::get('/master-data/opening-balance/import-template', [OpeningBalanceController::class, 'importTemplate']);
    Route::post('/master-data/opening-balance/import', [OpeningBalanceController::class, 'import']);
    Route::get('/master-data/opening-balance/import/active', [OpeningBalanceController::class, 'importActive']);
    Route::get('/master-data/opening-balance/import/{batch}/status', [OpeningBalanceController::class, 'importStatus']);
    Route::post('/master-data/opening-balance/import/{batch}/confirm-replace', [OpeningBalanceController::class, 'importConfirmReplace']);
    Route::post('/master-data/opening-balance/import/{batch}/confirm-skip', [OpeningBalanceController::class, 'importConfirmSkip']);
    Route::post('/master-data/opening-balance/import/{batch}/cancel', [OpeningBalanceController::class, 'importCancel']);

    // Mutasi master data (Perusahaan/Investor/Resto/Barang) — sebelumnya tanpa
    // role: middleware sama sekali (proteksi hanya di router Vue), siapapun yang
    // login bisa create/update/delete. Baca (index/show/export) tetap terbuka.
    Route::delete('/perusahaan/bulk', [PerusahaanController::class, 'bulkDestroy']);
    Route::delete('/investor/bulk', [InvestorController::class, 'bulkDestroy']);
    Route::delete('/resto/bulk', [RestoController::class, 'bulkDestroy']);
    Route::delete('/barang/bulk', [BarangController::class, 'bulkDestroy']);
    Route::apiResource('perusahaan', PerusahaanController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('investor', InvestorController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('resto', RestoController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('barang', BarangController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('brand', BrandController::class);
});

Route::apiResource('perusahaan', PerusahaanController::class)->only(['index', 'show']);
Route::apiResource('investor', InvestorController::class)->only(['index', 'show']);
Route::apiResource('resto', RestoController::class)->only(['index', 'show']);
Route::apiResource('barang', BarangController::class)->only(['index', 'show']);
