<?php

use App\Domain\Finance\Verification\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/verify/prepared/{token}', [VerificationController::class, 'prepared'])
    ->name('verify.prepared');

Route::get('/verify/approved/{token}', [VerificationController::class, 'approved'])
    ->name('verify.approved');
