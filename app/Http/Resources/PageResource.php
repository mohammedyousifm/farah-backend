<?php

namespace App\Http\Resources;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Page */
class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->tr('title'),
            'body' => $this->tr('body'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
