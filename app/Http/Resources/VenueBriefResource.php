<?php

namespace App\Http\Resources;

use App\Models\Venue;
use App\Support\Thumb;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The venue as shown inside a booking or an order: where to go and who to call.
 *
 * @mixin Venue
 */
class VenueBriefResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->tr('name'),
            'cover_url' => Thumb::url($this->cover_image),
            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'phone' => $this->phone,
            'city' => $this->whenLoaded('city', fn () => $this->city ? ['id' => $this->city->id, 'name' => $this->city->tr('name')] : null),
        ];
    }
}
