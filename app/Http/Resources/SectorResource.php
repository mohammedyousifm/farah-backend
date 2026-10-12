<?php

namespace App\Http\Resources;

use App\Models\Sector;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Sector
 *
 * @property-read int|null $venues_count only on the public list of activity types
 * @property-read Pivot|null $pivot only when loaded through a provider
 */
class SectorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->tr('name'),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'image_url' => $this->image ? Storage::disk('public')->url($this->image) : null,
            'description' => $this->tr('description'),
            'requires_license' => (bool) $this->requires_license,
            // Bookable venues offering this activity type (public list only).
            'venues_count' => $this->when(isset($this->venues_count), fn () => (int) $this->venues_count),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            // Only when listed through a provider (provider_sector pivot).
            'approval_status' => $this->whenPivotLoaded('provider_sector', fn () => $this->pivot->status),
        ];
    }
}
