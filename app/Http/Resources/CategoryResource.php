<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Category */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sector_id' => $this->sector_id,
            'parent_id' => $this->parent_id,
            'name' => $this->tr('name'),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'image_url' => $this->image ? Storage::disk('public')->url($this->image) : null,
            'children' => CategoryResource::collection($this->whenLoaded('children')),
        ];
    }
}
