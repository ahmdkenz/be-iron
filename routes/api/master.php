<?php

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

Route::delete('/perusahaan/bulk', [PerusahaanController::class, 'bulkDestroy']);

Route::get('/investor/export',                [InvestorController::class, 'export']);
Route::delete('/investor/bulk',               [InvestorController::class, 'bulkDestroy']);

Route::get('/resto/export',                   [RestoController::class, 'export']);
Route::delete('/resto/bulk',                  [RestoController::class, 'bulkDestroy']);

Route::get('/barang/external-catalog',      [BarangController::class, 'externalCatalog']);
Route::delete('/barang/bulk',               [BarangController::class, 'bulkDestroy']);

Route::middleware('role:ADMIN|MANAGER|SUPERVISOR')->group(function () {
    Route::get('/master-data/import-template',    [UnifiedMasterController::class, 'importTemplate']);
    Route::post('/master-data/import',            [UnifiedMasterController::class, 'import']);
    Route::get('/master-data/import/latest',      [UnifiedMasterController::class, 'latestImport']);
    Route::get('/master-data/import/{id}/status', [UnifiedMasterController::class, 'importStatus']);
});

Route::apiResource('perusahaan', PerusahaanController::class);
Route::apiResource('investor', InvestorController::class);
Route::apiResource('resto', RestoController::class);

Route::middleware('role:ADMIN|MANAGER|SUPERVISOR')->group(function () {
    Route::apiResource('brand', BrandController::class);
});

Route::apiResource('barang', BarangController::class);
