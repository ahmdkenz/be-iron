<?php

use App\Domain\Finance\AgingReport\Controllers\AgingReportController;
use App\Domain\Finance\Dashboard\Controllers\DashboardController;
use App\Domain\Finance\Invoice\Controllers\InvoiceController;
use App\Domain\Finance\JatuhTempo\Controllers\JatuhTempoController;
use App\Domain\Finance\KinerjaAr\Controllers\KinerjaArController;
use App\Domain\Finance\KlienAr\Controllers\KlienArController;
use App\Domain\Finance\MutasiPiutang\Controllers\MutasiPiutangController;
use App\Domain\Finance\RekeningKoran\Controllers\RekeningKoranController;
use App\Domain\Finance\RekonsiliasiBankStatement\Controllers\BankStatementController;
use App\Domain\Finance\OpeningBalance\Controllers\OpeningBalanceController;
use App\Domain\Finance\PembayaranAr\Controllers\PembayaranArController;
use App\Domain\Finance\RekapPembayaran\Controllers\RekapPembayaranController;
use Illuminate\Support\Facades\Route;

// ─── Master Klien AR ──────────────────────────────────────────────
Route::get('/dashboard/pic-ar', [DashboardController::class, 'picAr']);

Route::prefix('klien-ar')->group(function () {
    Route::get('/', [KlienArController::class, 'index']);
    Route::get('/all', [KlienArController::class, 'all']);
    Route::get('/preview-kode', [KlienArController::class, 'previewKode']);
    Route::get('/export', [KlienArController::class, 'export']);
    Route::get('/import-template', [KlienArController::class, 'importTemplate']);
    Route::post('/import', [KlienArController::class, 'import']);
    Route::post('/', [KlienArController::class, 'store']);
    Route::get('/{klien_ar}', [KlienArController::class, 'show']);
    Route::put('/{klien_ar}', [KlienArController::class, 'update']);
    Route::patch('/{klien_ar}/wa', [KlienArController::class, 'updateNoWa']);
    Route::delete('/{klien_ar}', [KlienArController::class, 'destroy']);
});

// ─── Invoice ──────────────────────────────────────────────────────
Route::prefix('invoices')->group(function () {
    Route::get('/', [InvoiceController::class, 'index']);
    Route::get('/summary', [InvoiceController::class, 'summary']);
    Route::get('/rekap-klien', [InvoiceController::class, 'rekapKlien']);
    Route::get('/export',          [InvoiceController::class, 'export']);
    Route::get('/export-excel',    [InvoiceController::class, 'exportExcel']);
    Route::get('/import-template', [InvoiceController::class, 'importTemplate']);
    Route::post('/import',         [InvoiceController::class, 'import']);
    Route::get('/{id}/print', [InvoiceController::class, 'print']);
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

// ─── Mutasi Piutang ───────────────────────────────────────────────
Route::get('/mutasi-piutang', [MutasiPiutangController::class, 'index']);

// ─── Rekening Koran ───────────────────────────────────────────────
Route::get('/rekening-koran', [RekeningKoranController::class, 'index']);
Route::get('/rekening-koran/export-excel', [RekeningKoranController::class, 'exportExcel']);
Route::get('/rekening-koran/export-pdf', [RekeningKoranController::class, 'exportPdf']);

// ─── Jatuh Tempo ──────────────────────────────────────────────────
Route::get('/jatuh-tempo', [JatuhTempoController::class, 'index']);

// ─── Rekap Pembayaran ─────────────────────────────────────────────
Route::get('/rekap-pembayaran', [RekapPembayaranController::class, 'index']);

// ─── Kinerja AR per PIC ───────────────────────────────────────────
Route::get('/kinerja-ar', [KinerjaArController::class, 'index']);

// ─── Rekonsiliasi Bank Statement ──────────────────────────────────
Route::prefix('rekonsiliasi-bank')->group(function () {
    Route::get('/',                                  [BankStatementController::class, 'index']);
    Route::post('/upload',                           [BankStatementController::class, 'upload']);
    Route::get('/template/{bankType}',               [BankStatementController::class, 'downloadTemplate']);
    Route::get('/{bankStatement}',                   [BankStatementController::class, 'show']);
    Route::delete('/{bankStatement}',                [BankStatementController::class, 'destroy']);
    Route::patch('/detail/{detail}/abaikan',         [BankStatementController::class, 'markDiabaikan']);
});

// ─── Opening Balance ──────────────────────────────────────────────
Route::prefix('opening-balance')->group(function () {
    Route::get('/', [OpeningBalanceController::class, 'index']);
    Route::get('/summary', [OpeningBalanceController::class, 'summary']);
    Route::get('/export', [OpeningBalanceController::class, 'export']);
    Route::get('/import-template', [OpeningBalanceController::class, 'importTemplate']);
    Route::post('/import', [OpeningBalanceController::class, 'import']);
    Route::post('/', [OpeningBalanceController::class, 'store']);
    Route::put('/{invoice}', [OpeningBalanceController::class, 'update']);
    Route::get('/{invoice}/details', [OpeningBalanceController::class, 'details']);
    Route::patch('/{invoice}/approve', [OpeningBalanceController::class, 'approve'])->middleware('role:DIREKTUR');
    Route::patch('/{invoice}/reject', [OpeningBalanceController::class, 'reject'])->middleware('role:DIREKTUR');
    Route::patch('/{invoice}/resubmit', [OpeningBalanceController::class, 'resubmit']);
});
