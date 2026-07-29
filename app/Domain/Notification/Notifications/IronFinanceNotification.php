<?php

namespace App\Domain\Notification\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Notifikasi aktivitas Finance (approval OB/EB, import, rekonsiliasi bank, dst).
 *
 * Sengaja TIDAK implements ShouldQueue: aplikasi ini hanya menjalankan queue
 * worker lewat scheduler tiap 1 menit (--stop-when-empty), jadi kalau seluruh
 * notify() di-queue, badge & broadcast realtime bisa telat sampai ~60 detik.
 * Channel 'database' otomatis sinkron begitu tidak di-queue; channel 'broadcast'
 * dipaksa sinkron lewat BroadcastMessage::onConnection('sync') di toBroadcast().
 */
class IronFinanceNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $category,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $routeName = null,
        public readonly array $routeParams = [],
        public readonly ?string $entityType = null,
        public readonly int|string|null $entityId = null,
        public readonly ?string $actorName = null,
        public readonly ?Carbon $occurredAt = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->payload() + ['id' => $this->id]))
            ->onConnection('sync');
    }

    public function broadcastType(): string
    {
        return 'finance.notification';
    }

    private function payload(): array
    {
        return [
            'category'      => $this->category,
            'severity'      => $this->severity,
            'title'         => $this->title,
            'body'          => $this->body,
            'route_name'    => $this->routeName,
            'route_params'  => $this->routeParams,
            'entity_type'   => $this->entityType,
            'entity_id'     => $this->entityId,
            'actor_name'    => $this->actorName,
            'occurred_at'   => ($this->occurredAt ?? now())->toIso8601String(),
        ];
    }
}
