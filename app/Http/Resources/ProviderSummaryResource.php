<?php

namespace App\Http\Resources;

use App\Models\Provider;
use App\Support\Thumb;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, minimal provider card.
 *
 * @mixin Provider
 */
class ProviderSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->displayName(),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'type' => $this->type?->toArray(),
            'logo_url' => Thumb::url($this->logo, Thumb::LOGO),
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
        ];
    }
}
