<?php

use App\Domain\Notification\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [NotificationController::class, 'index']);
Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
Route::patch('/read-all', [NotificationController::class, 'markAllRead']);
Route::patch('/{id}/read', [NotificationController::class, 'markRead']);
