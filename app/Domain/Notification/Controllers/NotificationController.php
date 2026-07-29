<?php

namespace App\Domain\Notification\Controllers;

use App\Domain\Notification\Resources\NotificationResource;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Semua query di controller ini WAJIB melalui relasi notifications()/
 * unreadNotifications() milik user yang sedang login — jangan pernah query
 * model DatabaseNotification langsung by id, supaya user lain tidak bisa
 * mengakses/menandai notifikasi milik user lain.
 */
class NotificationController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->notifications()->latest();

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $list = $query->paginate($request->integer('per_page', 20));

        return $this->paginatedResponse(
            $list->through(fn($notification) => new NotificationResource($notification))
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->successResponse([
            'count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();
        abort_if(!$notification, 404, 'Notifikasi tidak ditemukan');

        if (!$notification->read_at) {
            $notification->markAsRead();
        }

        return $this->successResponse(new NotificationResource($notification));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->successResponse(null, 'Semua notifikasi ditandai sudah dibaca');
    }
}
