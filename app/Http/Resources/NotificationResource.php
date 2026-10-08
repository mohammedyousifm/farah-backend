<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** In-app notification centre entry. @mixin \Illuminate\Notifications\DatabaseNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->data;
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'key' => $data['key'] ?? null,
            'title' => $data['title_'.$locale] ?? $data['title_ar'] ?? null,
            'body' => $data['body_'.$locale] ?? $data['body_ar'] ?? null,
            'data' => $data['data'] ?? [],
            'read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
