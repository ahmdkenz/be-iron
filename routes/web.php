<?php

use App\Domain\Finance\Invoice\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/print/invoice/{id}', [InvoiceController::class, 'print'])
    ->middleware('auth.query_token')
    ->name('invoice.print');
