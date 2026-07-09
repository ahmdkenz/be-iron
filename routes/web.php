<?php

use App\Domain\Finance\Invoice\Controllers\InvoiceController;
use App\Domain\Finance\PembayaranAr\Controllers\PembayaranArController;
use App\Domain\Finance\Verification\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/verify/prepared/{token}', [VerificationController::class, 'prepared'])
    ->name('verify.prepared');

Route::get('/verify/approved/{token}', [VerificationController::class, 'approved'])
    ->name('verify.approved');

Route::get('/invoices/print/{token}', [InvoiceController::class, 'publicPrint'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('invoice.public-print');

Route::get('/pembayaran/bukti/{pembayaran}', [PembayaranArController::class, 'publicBukti'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('pembayaran.public-bukti');
