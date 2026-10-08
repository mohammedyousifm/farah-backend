<?php

namespace App\Http\Resources;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Something to play in a venue, priced per hour. Customers only see active
 * resources; the provider app also gets inactive ones and the raw fields.
 *
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isProvider = $request->attributes->has('provider');
        $resources = $this->whenLoaded('resources',
            fn () => $isProvider ? $this->resources : $this->resources->where('is_active', true)->values());

        return [
            'id' => $this->id,
            'venue_id' => $this->venue_id,
            'name' => $this->tr('name'),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'description' => $this->tr('description'),
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'sector_id' => $this->sector_id,
            'category_id' => $this->category_id,
            'sector' => $this->whenLoaded('sector', fn () => $this->sector ? VenueCardResource::sector($this->sector) : null),
            'price_per_hour' => $this->price_per_hour,
            'currency' => config('farah.currency'),
            'slot_minutes' => $this->slot_minutes,
            'slot_price' => $this->slotPrice(),
            'min_slots' => $this->min_slots,
            'max_slots' => $this->max_slots,
            'capacity' => $this->capacity,
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,
            'resources_count' => $this->whenLoaded('resources', fn () => $this->resources->where('is_active', true)->count()),
            'resources' => ResourceResource::collection($resources),
        ];
    }
}
