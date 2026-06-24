<?php

use App\Domain\Master\Barang\Controllers\BarangController;
use App\Domain\Master\Brand\Controllers\BrandController;
use App\Domain\Master\Investor\Controllers\InvestorController;
use App\Domain\Master\Karyawan\Controllers\KaryawanController;
use App\Domain\Master\Perusahaan\Controllers\PerusahaanController;
use App\Domain\Master\Resto\Controllers\RestoController;
use Illuminate\Support\Facades\Route;

// Must declare named sub-routes BEFORE apiResource binding
Route::get('/karyawan/search', [KaryawanController::class, 'search']);
Route::delete('/karyawan/bulk', [KaryawanController::class, 'bulkDestroy']);

Route::delete('/perusahaan/bulk', [PerusahaanController::class, 'bulkDestroy']);

Route::get('/investor/export',                [InvestorController::class, 'export']);
Route::get('/investor/import-template',        [InvestorController::class, 'importTemplate']);
Route::post('/investor/import',               [InvestorController::class, 'import']);
Route::get('/investor/import/{id}/status',    [InvestorController::class, 'importStatus']);
Route::delete('/investor/bulk',               [InvestorController::class, 'bulkDestroy']);

Route::get('/resto/export',                   [RestoController::class, 'export']);
Route::get('/resto/import-template',          [RestoController::class, 'importTemplate']);
Route::post('/resto/import',                  [RestoController::class, 'import']);
Route::get('/resto/import/{id}/status',       [RestoController::class, 'importStatus']);
Route::delete('/resto/bulk',                  [RestoController::class, 'bulkDestroy']);

Route::delete('/barang/bulk', [BarangController::class, 'bulkDestroy']);

Route::apiResource('karyawan', KaryawanController::class);
Route::apiResource('perusahaan', PerusahaanController::class);
Route::apiResource('investor', InvestorController::class);
Route::apiResource('resto', RestoController::class);
Route::apiResource('brand', BrandController::class);
Route::apiResource('barang', BarangController::class);
