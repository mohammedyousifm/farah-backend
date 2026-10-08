<?php

namespace App\Http\Resources;

use App\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Complaint */
class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'type' => $this->type,
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => $this->status?->toArray(),
            'order_id' => $this->order_id,
            'venue_id' => $this->venue_id,
            'provider_id' => $this->provider_id,
            'resolution' => $this->resolution,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'replies' => $this->whenLoaded('replies', fn () => $this->replies
                ->where('is_internal', false)
                ->sortBy('id')
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'body' => $r->body,
                    'from_support' => $r->user_id !== $this->user_id,
                    'author' => $r->user_id === $this->user_id ? $r->user?->name : __('api.support_team'),
                    'created_at' => $r->created_at?->toIso8601String(),
                ])->values()),
        ];
    }
}
