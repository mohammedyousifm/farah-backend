<?php

namespace App\Http\Resources;

use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin City */
class CityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->tr('name'),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'region' => $this->region,
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];
    }
}
