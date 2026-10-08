<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One reserved resource for a run of slots. `code` is the unique code the app
 * renders as text/QR; the venue verifies it via POST /provider/bookings/check-in.
 *
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status?->toArray(),
            'name' => $this->displayName(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'slots' => $this->slots,
            'slot_minutes' => $this->slot_minutes,
            'unit_price' => $this->unit_price, // per slot
            'total' => $this->total,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'activity' => $this->whenLoaded('activity', fn () => ['id' => $this->activity_id, 'name' => $this->activity?->tr('name')]),
            'resource' => $this->whenLoaded('resource', fn () => ['id' => $this->resource_id, 'name' => $this->getRelation('resource')?->tr('name')]),
            'venue' => new VenueBriefResource($this->whenLoaded('venue')),
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->id,
                'number' => $this->order->number,
                'status' => $this->order->status?->toArray(),
            ]),
        ];
    }

    /** "Billiards - Table 3" in the current locale; the stored snapshot when the activity is not loaded. */
    private function displayName(): string
    {
        if ($this->relationLoaded('activity') && $this->relationLoaded('resource') && $this->activity && $this->getRelation('resource')) {
            return $this->activity->tr('name').' - '.$this->getRelation('resource')->tr('name');
        }

        return $this->name;
    }
}
