<?php

use App\Domain\Finance\ApShz360Sync\Controllers\ApShz360ImportController;
use App\Domain\Finance\Dashboard\Controllers\DashboardApController;
use App\Domain\Finance\OpeningBalanceAp\Controllers\OpeningBalanceApController;
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
    Route::get('/preview-no', [TagihanApController::class, 'previewNo']);
    Route::get('/outstanding', [TagihanApController::class, 'outstanding']);
    Route::post('/', [TagihanApController::class, 'store']);
    Route::delete('/bulk', [TagihanApController::class, 'bulkDestroy']);
    Route::get('/{id}/pembayaran', [TagihanApController::class, 'pembayaran']);
    Route::get('/{id}/approval-logs', [TagihanApController::class, 'approvalLogs']);
    Route::get('/{id}/print', [TagihanApController::class, 'print']);
    Route::get('/{id}', [TagihanApController::class, 'show']);
    Route::put('/{id}', [TagihanApController::class, 'update']);
    Route::delete('/{id}', [TagihanApController::class, 'destroy']);

    // Pembayaran per Tagihan
    Route::post('/{id}/pembayaran', [PembayaranApController::class, 'store']);
});

// ─── Pembayaran AP ──────────────────────────────────────────────
Route::get('/pembayaran', [PembayaranApController::class, 'index']);
Route::get('/pembayaran/cek-referensi', [PembayaranApController::class, 'cekReferensi']);
Route::delete('/pembayaran/{pembayaran}', [PembayaranApController::class, 'destroy']);

// ─── Opening Balance AP ───────────────────────────────────────────
Route::prefix('opening-balance')->group(function () {
    Route::get('/', [OpeningBalanceApController::class, 'index']);
    Route::get('/summary', [OpeningBalanceApController::class, 'summary']);
    Route::get('/preview-no', [OpeningBalanceApController::class, 'previewNo']);
    Route::post('/', [OpeningBalanceApController::class, 'store']);
    Route::get('/{id}', [OpeningBalanceApController::class, 'show']);
    Route::put('/{id}', [OpeningBalanceApController::class, 'update']);
    Route::get('/{id}/details', [OpeningBalanceApController::class, 'details']);
    Route::patch('/{id}/approve', [OpeningBalanceApController::class, 'approve'])->middleware('role:MANAGER|SUPERVISOR');
    Route::patch('/{id}/reject', [OpeningBalanceApController::class, 'reject'])->middleware('role:MANAGER|SUPERVISOR');
    Route::patch('/{id}/resubmit', [OpeningBalanceApController::class, 'resubmit']);
});

// ─── Import SHZ360 (staging PO/Terima PO -> Tagihan AP) ──────────
Route::prefix('shz360')->group(function () {
    Route::get('/sync/last-run', [ApShz360ImportController::class, 'lastSyncRun']);
    Route::post('/sync/retry', [ApShz360ImportController::class, 'retrySync']);
    Route::get('/imports', [ApShz360ImportController::class, 'index']);
    Route::get('/imports/{id}', [ApShz360ImportController::class, 'show']);
    Route::post('/imports/{id}/map-vendor', [ApShz360ImportController::class, 'mapVendor']);
    Route::post('/imports/{id}/create-vendor', [ApShz360ImportController::class, 'createVendor']);
    Route::post('/imports/{id}/convert-to-tagihan', [ApShz360ImportController::class, 'convertToTagihan']);
    Route::post('/imports/{id}/ignore', [ApShz360ImportController::class, 'ignore']);
});
