<?php

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\HomeSectionItem;
use App\Models\Provider;
use App\Models\Sector;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One home-screen row. `items[].kind` tells the app which card to render:
 * venue | category | sector | provider | banner.
 *
 * Built from ['section' => HomeSection, 'items' => Collection].
 */
class HomeSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $section = $this->resource['section'];

        return [
            'id' => $section->id,
            'title' => $section->tr('title'),
            'type' => $section->type,
            'layout' => $section->layout,
            'source' => $section->source,
            'items' => $this->resource['items']->map(fn ($item) => $this->item($item, $request))->values(),
        ];
    }

    private function item(mixed $item, Request $request): array
    {
        return match (true) {
            $item instanceof Venue => ['kind' => 'venue'] + (new VenueCardResource($item))->resolve($request),
            $item instanceof Category => ['kind' => 'category'] + (new CategoryResource($item))->resolve($request),
            $item instanceof Sector => ['kind' => 'sector'] + (new SectorResource($item))->resolve($request),
            $item instanceof Provider => ['kind' => 'provider'] + (new ProviderSummaryResource($item))->resolve($request),
            $item instanceof HomeSectionItem => [
                'kind' => 'banner',
                'id' => $item->id,
                'title' => $item->tr('title'),
                'media_type' => $item->media_type,
                'media_url' => $item->mediaSrc(),
                'link' => ['type' => $item->link_type, 'value' => $item->link_value],
            ],
            default => ['kind' => 'unknown'],
        };
    }
}
