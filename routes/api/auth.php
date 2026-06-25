<?php

use App\Domain\IAM\Auth\Controllers\AuthController;
use App\Domain\IAM\Auth\Controllers\EmailVerificationController;
use App\Domain\IAM\Auth\Controllers\ForgotPasswordController;
use App\Domain\IAM\Auth\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:20,1');

Route::post('/forgot-password', [ForgotPasswordController::class, 'sendLink'])->middleware('throttle:5,1');
Route::post('/reset-password', [ForgotPasswordController::class, 'reset'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

    Route::post('/email/send-verification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:3,1');
});
