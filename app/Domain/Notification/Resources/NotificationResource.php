<?php

namespace App\Domain\Notification\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Illuminate\Notifications\DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = (array) $this->data;

        return [
            'id'           => $this->id,
            'category'     => $data['category'] ?? null,
            'severity'     => $data['severity'] ?? 'info',
            'title'        => $data['title'] ?? null,
            'body'         => $data['body'] ?? null,
            'route_name'   => $data['route_name'] ?? null,
            'route_params' => $data['route_params'] ?? [],
            'entity_type'  => $data['entity_type'] ?? null,
            'entity_id'    => $data['entity_id'] ?? null,
            'actor_name'   => $data['actor_name'] ?? null,
            'occurred_at'  => $data['occurred_at'] ?? optional($this->created_at)->toIso8601String(),
            'read_at'      => optional($this->read_at)->toIso8601String(),
            'created_at'   => optional($this->created_at)->toIso8601String(),
        ];
    }
}
