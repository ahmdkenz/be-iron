<?php

use App\Domain\Finance\AgingReport\Controllers\AgingReportController;
use App\Domain\Finance\Dashboard\Controllers\DashboardController;
use App\Domain\Finance\Invoice\Controllers\InvoiceController;
use App\Domain\Finance\KlienAr\Controllers\KlienArController;
use App\Domain\Finance\OpeningBalance\Controllers\OpeningBalanceController;
use App\Domain\Finance\PembayaranAr\Controllers\PembayaranArController;
use Illuminate\Support\Facades\Route;

// ─── Master Klien AR ──────────────────────────────────────────────
Route::get('/dashboard/pic-ar', [DashboardController::class, 'picAr']);

Route::prefix('klien-ar')->group(function () {
    Route::get('/', [KlienArController::class, 'index']);
    Route::get('/all', [KlienArController::class, 'all']);
    Route::get('/preview-kode', [KlienArController::class, 'previewKode']);
    Route::post('/', [KlienArController::class, 'store']);
    Route::get('/{klien_ar}', [KlienArController::class, 'show']);
    Route::put('/{klien_ar}', [KlienArController::class, 'update']);
    Route::delete('/{klien_ar}', [KlienArController::class, 'destroy']);
});

// ─── Invoice ──────────────────────────────────────────────────────
Route::prefix('invoices')->group(function () {
    Route::get('/', [InvoiceController::class, 'index']);
    Route::get('/summary', [InvoiceController::class, 'summary']);
    Route::get('/rekap-klien', [InvoiceController::class, 'rekapKlien']);
    Route::get('/export', [InvoiceController::class, 'export']);
    Route::get('/carryover', [InvoiceController::class, 'carryover']);
    Route::get('/preview-no', [InvoiceController::class, 'previewNo']);
    Route::post('/', [InvoiceController::class, 'store']);
    Route::get('/{invoice}', [InvoiceController::class, 'show']);
    Route::put('/{invoice}', [InvoiceController::class, 'update']);
    Route::delete('/{invoice}', [InvoiceController::class, 'destroy']);
    Route::patch('/{invoice}/status', [InvoiceController::class, 'changeStatus']);

    // Pembayaran per Invoice
    Route::post('/{invoice}/pembayaran', [PembayaranArController::class, 'store']);
});

// ─── Pembayaran ───────────────────────────────────────────────────
Route::get('/pembayaran', [PembayaranArController::class, 'index']);
Route::delete('/pembayaran/{pembayaran}', [PembayaranArController::class, 'destroy']);

// ─── Aging Report ────────────────────────────────────────────────
Route::get('/aging-report', [AgingReportController::class, 'index']);

// ─── Opening Balance ──────────────────────────────────────────────
Route::prefix('opening-balance')->group(function () {
    Route::get('/', [OpeningBalanceController::class, 'index']);
    Route::get('/summary', [OpeningBalanceController::class, 'summary']);
    Route::post('/', [OpeningBalanceController::class, 'store']);
    Route::put('/{invoice}', [OpeningBalanceController::class, 'update']);
    Route::patch('/{invoice}/approve', [OpeningBalanceController::class, 'approve']);
    Route::patch('/{invoice}/reject', [OpeningBalanceController::class, 'reject']);
    Route::patch('/{invoice}/resubmit', [OpeningBalanceController::class, 'resubmit']);
});
