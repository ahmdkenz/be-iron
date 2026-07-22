<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ─── Public Routes ────────────────────────────────────────────────
    Route::prefix('auth')->group(base_path('routes/api/auth.php'));

    // ─── Protected Routes ─────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

        Route::prefix('master')->group(base_path('routes/api/master.php'));
        Route::prefix('iam')->group(base_path('routes/api/iam.php'));

        Route::prefix('finance')->group(function () {
            // Export & import: rate limit ketat untuk mencegah exfiltrasi & DoS
            Route::middleware('throttle:10,1')->group(function () {
                Route::get('invoices/export',              [\App\Domain\Finance\Invoice\Controllers\InvoiceController::class, 'export']);
                Route::get('invoices/export-excel',        [\App\Domain\Finance\Invoice\Controllers\InvoiceController::class, 'exportExcel']);
                Route::get('invoices/export-b2b-delivery', [\App\Domain\Finance\Invoice\Controllers\InvoiceController::class, 'exportB2BDelivery']);
                Route::post('invoices/import',             [\App\Domain\Finance\Invoice\Controllers\InvoiceController::class, 'import']);
                Route::post('klien-ar/import',             [\App\Domain\Finance\KlienAr\Controllers\KlienArController::class, 'import']);
                Route::get('klien-ar/export',              [\App\Domain\Finance\KlienAr\Controllers\KlienArController::class, 'export']);
                Route::get('aging-report/export-excel',    [\App\Domain\Finance\AgingReport\Controllers\AgingReportController::class, 'exportExcel']);
                Route::get('jurnal-pic/export-excel',      [\App\Domain\Finance\JurnalPic\Controllers\JurnalPicController::class, 'exportExcel']);
                Route::get('mutasi-piutang/export-excel',  [\App\Domain\Finance\MutasiPiutang\Controllers\MutasiPiutangController::class, 'exportExcel']);
                Route::get('rekap-pembayaran/export-excel',[\App\Domain\Finance\RekapPembayaran\Controllers\RekapPembayaranController::class, 'exportExcel']);
                Route::get('kinerja-ar/export-excel',      [\App\Domain\Finance\KinerjaAr\Controllers\KinerjaArController::class, 'exportExcel'])->middleware('role:ADMIN|MANAGER|SUPERVISOR');
                Route::get('pendapatan-di-muka/export-excel', [\App\Domain\Finance\PendapatanDiMuka\Controllers\PendapatanDiMukaController::class, 'exportExcel'])->middleware('role:ADMIN|MANAGER|SUPERVISOR');
                Route::get('opening-balance/export',       [\App\Domain\Finance\OpeningBalance\Controllers\OpeningBalanceController::class, 'export']);
                Route::post('rekonsiliasi-bank/upload',    [\App\Domain\Finance\RekonsiliasiBankStatement\Controllers\BankStatementController::class, 'upload']);
            });

            Route::group([], base_path('routes/api/finance.php'));
        });

        Route::prefix('ap')->group(base_path('routes/api/ap.php'));

    });
});
