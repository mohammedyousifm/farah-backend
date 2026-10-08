<?php

namespace App\Http\Resources;

use App\Models\VenueMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VenueMedia */
class VenueMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'url' => $this->src(),
            'is_external' => ! $this->path,
            'sort_order' => $this->sort_order,
        ];
    }
}
