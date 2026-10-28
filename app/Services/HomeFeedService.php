<?php

namespace App\Services;

use App\Enums\PlanFeature;
use App\Enums\ProviderStatus;
use App\Models\Category;
use App\Models\HomeSection;
use App\Models\HomeSectionItem;
use App\Models\Provider;
use App\Models\Sector;
use App\Models\User;
use App\Models\Venue;
use App\Support\Geo;
use Illuminate\Support\Collection;

/**
 * Builds the flexible home screen from admin-managed, schedulable sections.
 * Each section resolves to a list of venues / categories / sectors / providers
 * or manual banner items (image, video, GIF).
 */
class HomeFeedService
{
    public function __construct(
        private RecommendationService $recommendations,
        private SubscriptionService $subscriptions,
    ) {}

    /**
     * @param  array{lat: float, lng: float}|null  $point  the customer's position: every venue and store row lists the nearest first
     * @return Collection<int, array{section: HomeSection, items: Collection}>
     */
    public function build(?User $user, ?int $cityId = null, ?array $point = null): Collection
    {
        $sections = HomeSection::live()->with('items')->get();

        // Stores whose plan includes the moving banner get a slide in the first banner; with no banner set up
        // by the admins, one is opened for them at the top of the page.
        $banner = $sections->firstWhere('type', 'banner');
        if (! $banner && $this->sliderVenues()->isNotEmpty()) {
            $sections->prepend(new HomeSection(['type' => 'banner', 'layout' => 'hero', 'source' => 'manual', 'max_items' => 10]));
        }

        return $sections
            ->map(fn (HomeSection $section) => ['section' => $section, 'items' => $this->resolve($section, $user, $cityId, $point)])
            ->filter(fn ($row) => $row['items']->isNotEmpty())
            ->values();
    }

    public function resolve(HomeSection $section, ?User $user, ?int $cityId, ?array $point = null): Collection
    {
        $params = $section->source_params ?? [];
        $limit = $section->max_items;

        if ($section->type === 'banner') {
            return $this->isFirstBanner($section) ? $this->manual($section)->concat($this->slides()) : $this->manual($section);
        }
        if ($section->source === 'manual' && $section->type !== 'venues') {
            return $this->manual($section);
        }

        return match ($section->type) {
            'venues' => $this->venues($section, $params, $limit, $user, $cityId, $point),
            'categories' => Category::where('is_active', true)->whereNull('parent_id')
                ->when($params['sector_id'] ?? null, fn ($q, $id) => $q->where('sector_id', $id))
                ->orderBy('sort_order')->limit($limit)->get(),
            'sectors' => Sector::where('is_active', true)->orderBy('sort_order')->limit($limit)->get(),
            'providers' => $point
                ? $this->nearest(Provider::where('status', ProviderStatus::Approved)->orderByDesc('rating_avg')->get(), $point)->take($limit)->values()
                : Provider::where('status', ProviderStatus::Approved)->orderByDesc('rating_avg')->limit($limit)->get(),
            default => collect(),
        };
    }

    private function venues(HomeSection $section, array $params, int $limit, ?User $user, ?int $cityId, ?array $point): Collection
    {
        if ($section->source === 'recommended') {
            $venues = $this->recommendations->forUser($user, $limit, $cityId);

            return $point ? $this->nearest($venues, $point)->values() : $venues;
        }

        $query = $this->recommendations->cards(Venue::visible(), $user)
            ->when($cityId && ! $point, fn ($q) => $q->where('city_id', $cityId));

        // With the customer's position every row is "nearest first"; the section's own order breaks ties.
        if ($point) {
            Geo::orderByDistance(Geo::withDistance($query, $point['lat'], $point['lng']));
        }

        // Venues are matched to a sector / category through their active activities.
        $offering = fn (string $column, mixed $id) => $query
            ->whereHas('activities', fn ($q) => $q->where('is_active', true)->where($column, $id ?? 0))
            ->orderByDesc('is_featured')->orderByDesc('rating_avg');

        // "Featured" rows also carry the stores whose plan includes the home-page feature.
        $planned = $section->source === 'featured' ? $this->subscriptions->providerIdsWith(PlanFeature::HomeFeatured) : [];

        match ($section->source) {
            'manual' => $query->whereIn('id', $section->items->where('link_type', 'venue')->pluck('link_value')),
            'featured' => $query->where(fn ($q) => $q->where('is_featured', true)->orWhereIn('venues.provider_id', $planned))
                ->orderByDesc('rating_avg'),
            'top_rated' => $query->orderByDesc('rating_avg')->orderByDesc('rating_count'),
            'popular' => $query->withCount('orders')->orderByDesc('orders_count'),
            'category' => $offering('category_id', $params['category_id'] ?? null),
            'sector' => $offering('sector_id', $params['sector_id'] ?? null),
            // "upcoming" belonged to dated events; sections still set to it list the newest venues.
            default => $query->latest('published_at'),
        };

        return $query->limit($limit)->get();
    }

    /** Sorts loaded venues / providers by distance from the point (no coordinates = last) and sets `distance_km` on each. */
    private function nearest(Collection $rows, array $point): Collection
    {
        return $rows->each(function ($row) use ($point) {
            $row->distance_km = $row->lat !== null && $row->lng !== null
                ? Geo::km($point['lat'], $point['lng'], (float) $row->lat, (float) $row->lng)
                : null;
        })->sortBy(fn ($row) => $row->distance_km ?? INF);
    }

    /** Is this the banner that carries the plan slides (the first live banner, or the one opened for them)? */
    private function isFirstBanner(HomeSection $section): bool
    {
        $first = once(fn () => HomeSection::live()->where('type', 'banner')->value('id'));

        return ! $section->exists || $section->id === $first;
    }

    /** One slide per store with the "moving banner" plan feature: its cover, its name, a tap opens it. */
    private function slides(): Collection
    {
        return $this->sliderVenues()->map(fn (Venue $venue) => new HomeSectionItem([
            'title_ar' => $venue->name_ar,
            'title_en' => $venue->name_en,
            'media_type' => 'image',
            'media_url' => $venue->coverUrl(),
            'link_type' => 'venue',
            'link_value' => (string) $venue->id,
        ]));
    }

    /** Visible venues with a cover photo whose store's plan includes the moving banner (memoized per request). */
    private function sliderVenues(): Collection
    {
        return once(function () {
            $providers = $this->subscriptions->providerIdsWith(PlanFeature::HomeSlider);

            return $providers === [] ? collect() : Venue::visible()->whereIn('provider_id', $providers)
                ->whereNotNull('cover_image')->orderByDesc('rating_avg')->limit(10)->get();
        });
    }

    private function manual(HomeSection $section): Collection
    {
        return $section->items->map(fn (HomeSectionItem $item) => $item);
    }
}
