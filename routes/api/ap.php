<?php

use App\Domain\Finance\Dashboard\Controllers\DashboardApController;
use App\Domain\Finance\PembayaranAp\Controllers\PembayaranApController;
use App\Domain\Finance\TagihanAp\Controllers\TagihanApController;
use App\Domain\Finance\VendorAp\Controllers\VendorApController;
use Illuminate\Support\Facades\Route;

// ─── Dashboard ──────────────────────────────────────────────────
Route::get('/dashboard/summary', [DashboardApController::class, 'summary']);

// ─── Master Vendor ──────────────────────────────────────────────
Route::prefix('vendors')->group(function () {
    Route::get('/', [VendorApController::class, 'index']);
    Route::delete('/bulk', [VendorApController::class, 'bulkDestroy']);
    Route::post('/', [VendorApController::class, 'store']);
    Route::get('/{id}', [VendorApController::class, 'show']);
    Route::put('/{id}', [VendorApController::class, 'update']);
    Route::delete('/{id}', [VendorApController::class, 'destroy']);
});

// ─── Tagihan AP ───────────────────────────────────────────────────
Route::prefix('tagihan')->group(function () {
    Route::get('/', [TagihanApController::class, 'index']);
    Route::get('/summary', [TagihanApController::class, 'summary']);
    Route::get('/approval', [TagihanApController::class, 'approvalQueue']);
    Route::get('/preview-no', [TagihanApController::class, 'previewNo']);
    Route::post('/', [TagihanApController::class, 'store']);
    Route::delete('/bulk', [TagihanApController::class, 'bulkDestroy']);
    Route::get('/{id}/pembayaran', [TagihanApController::class, 'pembayaran']);
    Route::get('/{id}/approval-logs', [TagihanApController::class, 'approvalLogs']);
    Route::get('/{id}', [TagihanApController::class, 'show']);
    Route::put('/{id}', [TagihanApController::class, 'update']);
    Route::delete('/{id}', [TagihanApController::class, 'destroy']);
    Route::patch('/{id}/approve', [TagihanApController::class, 'approve'])->middleware('role:ADMIN|MANAGER|SUPERVISOR');
    Route::patch('/{id}/reject', [TagihanApController::class, 'reject'])->middleware('role:ADMIN|MANAGER|SUPERVISOR');
    Route::patch('/{id}/resubmit', [TagihanApController::class, 'resubmit']);

    // Pembayaran per Tagihan
    Route::post('/{id}/pembayaran', [PembayaranApController::class, 'store']);
});

// ─── Pembayaran AP ──────────────────────────────────────────────
Route::get('/pembayaran', [PembayaranApController::class, 'index']);
Route::get('/pembayaran/cek-referensi', [PembayaranApController::class, 'cekReferensi']);
Route::delete('/pembayaran/{pembayaran}', [PembayaranApController::class, 'destroy']);
