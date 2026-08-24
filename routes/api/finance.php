<?php

use App\Domain\Finance\AgingReport\Controllers\AgingReportController;
use App\Domain\Finance\Dashboard\Controllers\DashboardController;
use App\Domain\Finance\EndingBalance\Controllers\EndingBalanceController;
use App\Domain\Finance\EndingBalance\Controllers\EndingBalanceKoreksiController;
use App\Domain\Finance\Invoice\Controllers\InvoiceController;
use App\Domain\Finance\Invoice\Controllers\InvoiceImportController;
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
    Route::get('/{klien_ar}', [KlienArController::class, 'show']);

    // Mutasi data klien AR — sebelumnya tanpa role: middleware sama sekali
    // (proteksi hanya di router Vue), siapapun yang login bisa create/update/
    // delete master data klien. Digating menyusul pola grup pembayaran/PDM di bawah.
    Route::middleware('role:ADMIN|MANAGER|SUPERVISOR|AR')->group(function () {
        Route::delete('/bulk', [KlienArController::class, 'bulkDestroy']);
        Route::post('/', [KlienArController::class, 'store']);
        Route::put('/{klien_ar}', [KlienArController::class, 'update']);
        Route::patch('/{klien_ar}/wa', [KlienArController::class, 'updateNoWa']);
        Route::delete('/{klien_ar}', [KlienArController::class, 'destroy']);
    });
});

// ─── Invoice ──────────────────────────────────────────────────────
Route::prefix('invoices')->group(function () {
    // Import invoice (tab "Import Master Invoice"). Harus terdaftar sebelum
    // rute /{invoice} di bawah — kalau tidak, "import" akan ditangkap sebagai
    // parameter {invoice} dan tidak pernah sampai ke sini.
    Route::get('/import-template',                  [InvoiceImportController::class, 'template']);
    Route::post('/import',                          [InvoiceImportController::class, 'store']);
    Route::get('/import/active',                    [InvoiceImportController::class, 'active']);
    // 'latest' & 'active' harus di atas '/import/{batch}/...' — keduanya segmen
    // literal, jadi urutannya aman, tapi tetap dikelompokkan supaya jelas.
    Route::get('/import/latest',                    [InvoiceImportController::class, 'latestImport']);
    Route::get('/import/{batch}/status',            [InvoiceImportController::class, 'status']);
    Route::get('/import/{batch}/change-log',        [InvoiceImportController::class, 'changeLog']);
    Route::post('/import/{batch}/apply-safe',       [InvoiceImportController::class, 'applySafe']);
    Route::post('/import/{batch}/cancel',           [InvoiceImportController::class, 'cancel']);

    Route::get('/', [InvoiceController::class, 'index']);
    Route::get('/summary', [InvoiceController::class, 'summary']);
    Route::get('/rekap-klien', [InvoiceController::class, 'rekapKlien']);
    Route::get('/rekap-klien/export-count', [InvoiceController::class, 'exportRekapKlienRowCount']);
    Route::get('/export',               [InvoiceController::class, 'export']);
    Route::get('/export-excel',         [InvoiceController::class, 'exportExcel']);
    Route::get('/export-count',         [InvoiceController::class, 'exportRowCount']);
    Route::get('/export-b2b-delivery',  [InvoiceController::class, 'exportB2BDelivery']);
    Route::get('/{id}/print', [InvoiceController::class, 'print']);
    Route::get('/{id}/print/status', [InvoiceController::class, 'printStatus']);
    Route::get('/carryover', [InvoiceController::class, 'carryover']);
    Route::get('/outstanding', [InvoiceController::class, 'outstanding']);
    Route::get('/outstanding-bulk', [InvoiceController::class, 'outstandingBulk']);
    Route::get('/preview-no', [InvoiceController::class, 'previewNo']);
    Route::get('/preview-no-konsolidasi', [InvoiceController::class, 'previewConsolidatedNo']);
    Route::post('/email-blast', [InvoiceController::class, 'emailBlast'])->middleware('throttle:15,1,email-blast');
    Route::get('/email-blast/{batch}/status', [InvoiceController::class, 'emailBlastStatus']);
    // Mutasi invoice — sebelumnya tanpa role: middleware sama sekali (proteksi
    // hanya di router Vue), siapapun yang login bisa create/update/hapus invoice
    // manapun. Ownership per-klien (PIC AR) sendiri dicek di controller
    // (authorizeInvoiceAccess).
    Route::middleware('role:ADMIN|MANAGER|SUPERVISOR|AR')->group(function () {
        Route::post('/', [InvoiceController::class, 'store']);
        Route::delete('/bulk', [InvoiceController::class, 'bulkDestroy']);
    });
    Route::get('/{invoice}/settleable-originals', [InvoiceController::class, 'settleableOriginals']);
    Route::get('/{invoice}/items', [InvoiceController::class, 'items']);
    Route::get('/{invoice}/pembayaran', [InvoiceController::class, 'pembayaran']);
    Route::get('/{invoice}/approval-logs', [InvoiceController::class, 'approvalLogs']);
    Route::get('/{invoice}/koreksi', [InvoiceController::class, 'koreksi']);
    Route::get('/{invoice}', [InvoiceController::class, 'show']);
    Route::middleware('role:ADMIN|MANAGER|SUPERVISOR|AR')->group(function () {
        Route::put('/{invoice}', [InvoiceController::class, 'update']);
        Route::delete('/{invoice}', [InvoiceController::class, 'destroy']);
        Route::patch('/{invoice}/status', [InvoiceController::class, 'changeStatus']);
        Route::patch('/{invoice}/recalculate', [InvoiceController::class, 'recalculate']);
    });
});

// ─── Pembayaran ───────────────────────────────────────────────────
// Sebelumnya tanpa role: middleware sama sekali (proteksi hanya di router Vue).
// Ownership per-klien (PIC AR) sendiri sudah dicek di controller.
Route::middleware('role:ADMIN|MANAGER|SUPERVISOR|AR')->group(function () {
    Route::get('/pembayaran', [PembayaranArController::class, 'index']);
    Route::delete('/pembayaran/{pembayaran}', [PembayaranArController::class, 'destroy']);
    Route::delete('/pembayaran/{pembayaran}/items/{item}', [PembayaranArController::class, 'destroyItem']);
});

// ─── Jurnal per PIC ───────────────────────────────────────────────
Route::get('/jurnal-pic',                  [JurnalPicController::class, 'index']);
Route::get('/jurnal-pic/by-referensi',     [JurnalPicController::class, 'byReferensi']);
Route::get('/jurnal-pic/export-excel',     [JurnalPicController::class, 'exportExcel']);

// ─── Aging Report ────────────────────────────────────────────────
Route::get('/aging-report', [AgingReportController::class, 'index']);
Route::get('/aging-report/export-excel', [AgingReportController::class, 'exportExcel']);
Route::get('/aging-report/export-count', [AgingReportController::class, 'exportRowCount']);

// ─── Mutasi Piutang ───────────────────────────────────────────────
Route::get('/mutasi-piutang', [MutasiPiutangController::class, 'index']);
Route::get('/mutasi-piutang/export-excel', [MutasiPiutangController::class, 'exportExcel']);
Route::get('/mutasi-piutang/export-count', [MutasiPiutangController::class, 'exportRowCount']);

// ─── Rekening Koran (Jurnal Umum Bank Statement) ──────────────────
// Laporan global lintas PIC — hanya ADMIN/MANAGER/SUPERVISOR. Status posting
// kini otomatis (lihat BankStatementService), tidak ada lagi endpoint manual.
// Rute export-excel-nya terdaftar di routes/api.php (grup throttle:10,1) bersama
// export laporan lain, bukan di sini.
Route::get('/rekening-koran',                          [RekeningKoranController::class, 'index'])->middleware('role:ADMIN|MANAGER|SUPERVISOR');
Route::get('/rekening-koran/pic-ar-list',              [RekeningKoranController::class, 'picArList'])->middleware('role:ADMIN|MANAGER|SUPERVISOR');
Route::get('/rekening-koran/export-count',             [RekeningKoranController::class, 'exportRowCount'])->middleware('role:ADMIN|MANAGER|SUPERVISOR');

// ─── Jatuh Tempo ──────────────────────────────────────────────────
Route::get('/jatuh-tempo', [JatuhTempoController::class, 'index']);

// ─── Rekap Pembayaran ─────────────────────────────────────────────
Route::get('/rekap-pembayaran', [RekapPembayaranController::class, 'index']);
Route::get('/rekap-pembayaran/export-excel', [RekapPembayaranController::class, 'exportExcel']);

// ─── Kinerja AR per PIC ───────────────────────────────────────────
// Laporan komparatif lintas PIC — hanya ADMIN/MANAGER/SUPERVISOR.
Route::get('/kinerja-ar', [KinerjaArController::class, 'index'])->middleware('role:ADMIN|MANAGER|SUPERVISOR');

// ─── Rekonsiliasi Bank Statement ──────────────────────────────────
Route::prefix('rekonsiliasi-bank')->middleware('role:ADMIN|MANAGER|SUPERVISOR|AR|AP')->group(function () {
    Route::get('/',                                  [BankStatementController::class, 'index']);
    Route::post('/upload',                           [BankStatementController::class, 'upload'])->middleware('role:ADMIN|MANAGER|SUPERVISOR');
    Route::get('/template/{bankType}',               [BankStatementController::class, 'downloadTemplate']);
    // Harus terdaftar sebelum /{bankStatement} — kalau tidak, "imports" akan
    // ditangkap sebagai parameter {bankStatement} dan tidak pernah sampai ke sini.
    Route::get('/imports/{batch}/status',            [BankStatementController::class, 'importStatus'])->middleware('role:ADMIN|MANAGER|SUPERVISOR');
    Route::post('/imports/{batch}/confirm-replace',  [BankStatementController::class, 'confirmReplace'])->middleware('role:ADMIN|MANAGER|SUPERVISOR');
    Route::post('/imports/{batch}/cancel',           [BankStatementController::class, 'cancelImport'])->middleware('role:ADMIN|MANAGER|SUPERVISOR');
    Route::get('/{bankStatement}',                   [BankStatementController::class, 'show']);
    Route::get('/{bankStatement}/header',            [BankStatementController::class, 'header']);
    Route::get('/{bankStatement}/details',           [BankStatementController::class, 'paginatedDetails']);
    Route::delete('/{bankStatement}',                [BankStatementController::class, 'destroy'])->middleware('role:ADMIN|MANAGER|SUPERVISOR');
    Route::patch('/detail/{detail}/abaikan',         [BankStatementController::class, 'markDiabaikan']);
    Route::get('/detail/{detail}/kandidat',          [BankStatementController::class, 'kandidat']);
    Route::patch('/detail/{detail}/match',           [BankStatementController::class, 'matchDetail']);
    Route::patch('/detail/{detail}/unmatch',         [BankStatementController::class, 'unmatchDetail']);
    Route::get('/detail/{detail}/invoice-b2c',       [BankStatementController::class, 'invoiceB2C']);
    Route::get('/detail/{detail}/invoice-b2b',       [BankStatementController::class, 'invoiceB2B']);
    Route::post('/detail/{detail}/kelebihan',        [BankStatementController::class, 'applyKelebihanBayar']);
    Route::get('/detail/{detail}/invoice-candidates', [BankStatementController::class, 'invoiceCandidates']);
    Route::post('/detail/{detail}/catat-bayar',       [BankStatementController::class, 'catatBayar']);
    Route::post('/detail/{detail}/catat-bayar-multi', [BankStatementController::class, 'catatBayarMulti']);
    Route::post('/detail/{detail}/catat-pdm',         [BankStatementController::class, 'catatPdm']);
    Route::get('/detail/{detail}/tagihan-ap-candidates', [BankStatementController::class, 'tagihanApCandidates']);
    Route::post('/detail/{detail}/catat-voucher-ap',      [BankStatementController::class, 'catatVoucherAp']);
});

// ─── Pendapatan di Muka ───────────────────────────────────────────
// Index/export dibuka untuk AR juga (sebelumnya ADMIN/MANAGER/SUPERVISOR-only,
// yang membuat PIC AR tidak bisa "Gunakan" PDM kliennya sendiri untuk melunasi
// invoice baru) — controller men-scope hasil ke klien milik PIC AR yang login,
// ADMIN/MANAGER/SUPERVISOR tetap melihat semua (lihat PendapatanDiMukaController).
// Aksi catat/batal/gunakan juga kini digating role yang sama (defense in depth,
// ownership per-klien tetap dicek di controller).
// export-excel didaftarkan terpisah di routes/api.php (grup throttle export
// laporan lain) — role middleware-nya diupdate di sana juga, jangan didaftarkan
// dobel di sini.
Route::middleware('role:ADMIN|MANAGER|SUPERVISOR|AR')->group(function () {
    Route::get('/pendapatan-di-muka',                           [PendapatanDiMukaController::class, 'index']);
    Route::get('/pendapatan-di-muka/export-count',              [PendapatanDiMukaController::class, 'exportRowCount']);
    Route::post('/pendapatan-di-muka/detail/{detail}/catat',    [PendapatanDiMukaController::class, 'store']);
    Route::delete('/pendapatan-di-muka/{pdm}/batal',            [PendapatanDiMukaController::class, 'cancel']);
    Route::post('/pendapatan-di-muka/{pdm}/gunakan',            [PendapatanDiMukaController::class, 'gunakan']);
});

// ─── Ending Balance ───────────────────────────────────────────────
Route::prefix('ending-balance')->group(function () {
    Route::get('/',                   [EndingBalanceController::class, 'index']);
    Route::get('/{id}/invoices',      [EndingBalanceController::class, 'invoices']);
    Route::get('/{id}/payments',      [EndingBalanceController::class, 'payments']);
    Route::get('/{id}',               [EndingBalanceController::class, 'show']);
    Route::patch('/{id}/lock',        [EndingBalanceController::class, 'lock']);
    Route::patch('/{id}/unlock',      [EndingBalanceController::class, 'unlock']);
    Route::patch('/{id}/recalculate', [EndingBalanceController::class, 'recalculate']);

    // Koreksi
    Route::post('/{ebId}/koreksi',                         [EndingBalanceKoreksiController::class, 'store']);
    Route::get('/koreksi/pending',                         [EndingBalanceKoreksiController::class, 'pending']);
    Route::get('/koreksi/approved',                        [EndingBalanceKoreksiController::class, 'approved']);
    Route::patch('/koreksi/{id}/approve-spv',              [EndingBalanceKoreksiController::class, 'approveSpv'])->middleware('role:SUPERVISOR|ADMIN');
    Route::patch('/koreksi/{id}/reject-spv',               [EndingBalanceKoreksiController::class, 'rejectSpv'])->middleware('role:SUPERVISOR|ADMIN');
    Route::patch('/koreksi/{id}/approve-manager',          [EndingBalanceKoreksiController::class, 'approveManager'])->middleware('role:MANAGER|SUPERVISOR|ADMIN');
    Route::patch('/koreksi/{id}/reject-manager',           [EndingBalanceKoreksiController::class, 'rejectManager'])->middleware('role:MANAGER|SUPERVISOR|ADMIN');
    Route::get('/koreksi/{id}/print',                      [EndingBalanceKoreksiController::class, 'printDocument']);
});

// ─── Opening Balance ──────────────────────────────────────────────
Route::prefix('opening-balance')->group(function () {
    Route::get('/', [OpeningBalanceController::class, 'index']);
    Route::get('/summary', [OpeningBalanceController::class, 'summary']);
    // GET /export didaftarkan di api.php (grup throttle:10,1) — jangan didaftarkan ulang di sini,
    // registrasi kedua akan menang & diam-diam menghilangkan throttle yang dimaksud untuk endpoint export.
    Route::get('/export-count', [OpeningBalanceController::class, 'exportRowCount']);
    Route::post('/', [OpeningBalanceController::class, 'store']);
    Route::post('/bulk', [OpeningBalanceController::class, 'storeBulk']);
    Route::put('/{invoice}', [OpeningBalanceController::class, 'update']);
    Route::delete('/{invoice}', [OpeningBalanceController::class, 'destroy']);
    Route::get('/{invoice}/details', [OpeningBalanceController::class, 'details']);
    Route::patch('/bulk-approve', [OpeningBalanceController::class, 'bulkApprove'])->middleware('role:MANAGER|SUPERVISOR');
    Route::get('/bulk-approve/active', [OpeningBalanceController::class, 'bulkApproveActive'])->middleware('role:MANAGER|SUPERVISOR');
    Route::get('/bulk-approve/{batch}/status', [OpeningBalanceController::class, 'bulkApproveStatus'])->middleware('role:MANAGER|SUPERVISOR');
    Route::patch('/{invoice}/approve', [OpeningBalanceController::class, 'approve'])->middleware('role:MANAGER|SUPERVISOR');
    Route::patch('/{invoice}/reject', [OpeningBalanceController::class, 'reject'])->middleware('role:MANAGER|SUPERVISOR');
    Route::patch('/{invoice}/resubmit', [OpeningBalanceController::class, 'resubmit']);
});
