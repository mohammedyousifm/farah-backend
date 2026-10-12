<?php

namespace App\Http\Resources;

use App\Models\Venue;
use App\Services\AvailabilityService;
use App\Support\Thumb;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact venue for lists, rows and search results.
 *
 * @mixin Venue
 */
class VenueCardResource extends JsonResource
{
    /** Relations a card needs; eager load them wherever venues are listed. */
    public const RELATIONS = ['city', 'provider', 'hours', 'activities.sector'];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->tr('name'),
            'summary' => $this->tr('summary'),
            'cover_url' => Thumb::url($this->cover_image),
            'min_price' => $this->min_price, // per hour
            'currency' => config('farah.currency'),
            'is_featured' => (bool) $this->is_featured,
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'distance_km' => isset($this->distance_km) ? round((float) $this->distance_km, 1) : null,
            'status' => $this->status?->toArray(),
            'is_favorite' => (bool) ($this->is_favorite ?? false),
            'is_open_now' => $this->isOpenNow(),
            'today_hours' => $this->todayHours(),
            'city' => new CityResource($this->whenLoaded('city')),
            'sectors' => $this->whenLoaded('activities', fn () => $this->activities
                ->where('is_active', true)
                ->map(fn ($activity) => $activity->sector)
                ->filter()
                ->unique('id')
                ->map(fn ($sector) => self::sector($sector))
                ->values()),
            'provider' => new ProviderSummaryResource($this->whenLoaded('provider')),
        ];
    }

    /** Activity type as shown on cards and activities. */
    public static function sector($sector): array
    {
        return ['id' => $sector->id, 'slug' => $sector->slug, 'name' => $sector->tr('name'), 'icon' => $sector->icon];
    }

    /** Working hours of today's weekday in the business timezone, null when closed. */
    protected function todayHours(): ?array
    {
        $hours = $this->hours->firstWhere('weekday', CarbonImmutable::now(AvailabilityService::timezone())->dayOfWeek);

        return $hours && $hours->isOpen()
            ? ['opens_at' => $hours->opens(), 'closes_at' => $hours->closes()]
            : null;
    }

    /** Inside today's window, or yesterday's when it runs past midnight. */
    protected function isOpenNow(): bool
    {
        $availability = app(AvailabilityService::class);
        $now = CarbonImmutable::now(AvailabilityService::timezone());

        foreach ([$now, $now->subDay()] as $day) {
            $window = $availability->window($this->resource, $day);
            if ($window && $now->greaterThanOrEqualTo($window[0]) && $now->lessThan($window[1])) {
                return true;
            }
        }

        return false;
    }
}
