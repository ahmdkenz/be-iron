<?php

use App\Domain\IAM\User\Controllers\UserController;
use App\Domain\IAM\Role\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

// Roles — ADMIN only
Route::middleware('role:ADMIN')->group(function () {
    Route::delete('/roles/bulk', [RoleController::class, 'bulkDestroy']);
    Route::apiResource('roles', RoleController::class);
    Route::delete('/users/bulk', [UserController::class, 'bulkDestroy']);
    Route::apiResource('users', UserController::class);
});
