<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public, minimal provider card. @mixin \App\Models\Provider */
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
            'logo_url' => $this->logoUrl(),
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
        ];
    }
}
