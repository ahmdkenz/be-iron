<?php

use App\Domain\Finance\AgingReport\Controllers\AgingReportController;
use App\Domain\Finance\Dashboard\Controllers\DashboardController;
use App\Domain\Finance\Invoice\Controllers\InvoiceController;
use App\Domain\Finance\JatuhTempo\Controllers\JatuhTempoController;
use App\Domain\Finance\JurnalPic\Controllers\JurnalPicController;
use App\Domain\Finance\KinerjaAr\Controllers\KinerjaArController;
use App\Domain\Finance\KlienAr\Controllers\KlienArController;
use App\Domain\Finance\MutasiPiutang\Controllers\MutasiPiutangController;
use App\Domain\Finance\RekeningKoran\Controllers\RekeningKoranController;
use App\Domain\Finance\RekonsiliasiBankStatement\Controllers\BankStatementController;
use App\Domain\Finance\OpeningBalance\Controllers\OpeningBalanceController;
use App\Domain\Finance\PembayaranAr\Controllers\PembayaranArController;
use App\Domain\Finance\PendapatanDiMuka\Controllers\PendapatanDiMukaController;
use App\Domain\Finance\RekapPembayaran\Controllers\RekapPembayaranController;
use Illuminate\Support\Facades\Route;

// ─── Master Client ──────────────────────────────────────────────
Route::get('/dashboard/pic-ar',      [DashboardController::class, 'picAr']);
Route::get('/dashboard/global',      [DashboardController::class, 'global']);
Route::get('/dashboard/kpi',         [DashboardController::class, 'kpi']);
Route::get('/dashboard/top-clients', [DashboardController::class, 'topClients']);

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
    Route::get('/export',               [InvoiceController::class, 'export']);
    Route::get('/export-excel',         [InvoiceController::class, 'exportExcel']);
    Route::get('/export-b2b-delivery',  [InvoiceController::class, 'exportB2BDelivery']);
    Route::get('/import-template', [InvoiceController::class, 'importTemplate']);
    Route::post('/import',         [InvoiceController::class, 'import']);
    Route::get('/{id}/print', [InvoiceController::class, 'print']);
    Route::get('/carryover', [InvoiceController::class, 'carryover']);
    Route::get('/preview-no', [InvoiceController::class, 'previewNo']);
    Route::post('/', [InvoiceController::class, 'store']);
    Route::delete('/bulk', [InvoiceController::class, 'bulkDestroy']);
    Route::get('/{invoice}', [InvoiceController::class, 'show']);
    Route::put('/{invoice}', [InvoiceController::class, 'update']);
    Route::delete('/{invoice}', [InvoiceController::class, 'destroy']);
    Route::patch('/{invoice}/status', [InvoiceController::class, 'changeStatus']);

    // Pembayaran per Invoice
    Route::post('/{invoice}/pembayaran', [PembayaranArController::class, 'store']);
});

// ─── Pembayaran ───────────────────────────────────────────────────
Route::get('/pembayaran', [PembayaranArController::class, 'index']);
Route::get('/pembayaran/cek-referensi', [PembayaranArController::class, 'cekReferensi']);
Route::delete('/pembayaran/{pembayaran}', [PembayaranArController::class, 'destroy']);

// ─── Jurnal per PIC ───────────────────────────────────────────────
Route::get('/jurnal-pic',                  [JurnalPicController::class, 'index']);
Route::get('/jurnal-pic/by-referensi',     [JurnalPicController::class, 'byReferensi']);
Route::get('/jurnal-pic/export-excel',     [JurnalPicController::class, 'exportExcel']);

// ─── Aging Report ────────────────────────────────────────────────
Route::get('/aging-report', [AgingReportController::class, 'index']);
Route::get('/aging-report/export-excel', [AgingReportController::class, 'exportExcel']);

// ─── Mutasi Piutang ───────────────────────────────────────────────
Route::get('/mutasi-piutang', [MutasiPiutangController::class, 'index']);
Route::get('/mutasi-piutang/export-excel', [MutasiPiutangController::class, 'exportExcel']);

// ─── Rekening Koran ───────────────────────────────────────────────
Route::get('/rekening-koran', [RekeningKoranController::class, 'index']);
Route::get('/rekening-koran/export-excel', [RekeningKoranController::class, 'exportExcel']);
Route::get('/rekening-koran/export-pdf', [RekeningKoranController::class, 'exportPdf']);

// ─── Jatuh Tempo ──────────────────────────────────────────────────
Route::get('/jatuh-tempo', [JatuhTempoController::class, 'index']);

// ─── Rekap Pembayaran ─────────────────────────────────────────────
Route::get('/rekap-pembayaran', [RekapPembayaranController::class, 'index']);
Route::get('/rekap-pembayaran/export-excel', [RekapPembayaranController::class, 'exportExcel']);

// ─── Kinerja AR per PIC ───────────────────────────────────────────
Route::get('/kinerja-ar', [KinerjaArController::class, 'index']);
Route::get('/kinerja-ar/export-excel', [KinerjaArController::class, 'exportExcel']);

// ─── Rekonsiliasi Bank Statement ──────────────────────────────────
Route::prefix('rekonsiliasi-bank')->group(function () {
    Route::get('/',                                  [BankStatementController::class, 'index']);
    Route::post('/upload',                           [BankStatementController::class, 'upload']);
    Route::get('/template/{bankType}',               [BankStatementController::class, 'downloadTemplate']);
    Route::get('/{bankStatement}',                   [BankStatementController::class, 'show']);
    Route::delete('/{bankStatement}',                [BankStatementController::class, 'destroy']);
    Route::patch('/detail/{detail}/abaikan',         [BankStatementController::class, 'markDiabaikan']);
    Route::get('/detail/{detail}/kandidat',          [BankStatementController::class, 'kandidat']);
    Route::patch('/detail/{detail}/match',           [BankStatementController::class, 'matchDetail']);
    Route::patch('/detail/{detail}/unmatch',         [BankStatementController::class, 'unmatchDetail']);
    Route::get('/detail/{detail}/invoice-b2c',       [BankStatementController::class, 'invoiceB2C']);
    Route::get('/detail/{detail}/invoice-b2b',       [BankStatementController::class, 'invoiceB2B']);
    Route::post('/detail/{detail}/kelebihan',        [BankStatementController::class, 'applyKelebihanBayar']);
});

// ─── Pendapatan di Muka ───────────────────────────────────────────
Route::get('/pendapatan-di-muka',                           [PendapatanDiMukaController::class, 'index']);
Route::get('/pendapatan-di-muka/export-excel',              [PendapatanDiMukaController::class, 'exportExcel']);
Route::post('/pendapatan-di-muka/detail/{detail}/catat',    [PendapatanDiMukaController::class, 'store']);
Route::delete('/pendapatan-di-muka/{pdm}/batal',            [PendapatanDiMukaController::class, 'cancel']);
Route::post('/pendapatan-di-muka/{pdm}/gunakan',            [PendapatanDiMukaController::class, 'gunakan']);

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
