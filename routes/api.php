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
    Route::middleware('auth:sanctum')->group(function () {

        Route::prefix('master')->group(base_path('routes/api/master.php'));
        Route::prefix('iam')->group(base_path('routes/api/iam.php'));
        Route::prefix('finance')->group(base_path('routes/api/finance.php'));

    });
});
