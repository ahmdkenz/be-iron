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
Route::get('/resto/preview-kode', [RestoController::class, 'previewKode']);

Route::apiResource('karyawan', KaryawanController::class);
Route::apiResource('perusahaan', PerusahaanController::class);
Route::apiResource('investor', InvestorController::class);
Route::apiResource('resto', RestoController::class);
Route::apiResource('brand', BrandController::class);
Route::apiResource('barang', BarangController::class);
