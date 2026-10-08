<?php

namespace App\Services;

use App\Enums\ProviderStatus;
use App\Models\Category;
use App\Models\HomeSection;
use App\Models\HomeSectionItem;
use App\Models\Provider;
use App\Models\Sector;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Collection;

/**
 * Builds the flexible home screen from admin-managed, schedulable sections.
 * Each section resolves to a list of venues / categories / sectors / providers
 * or manual banner items (image, video, GIF).
 */
class HomeFeedService
{
    public function __construct(private RecommendationService $recommendations) {}

    /** @return Collection<int, array{section: HomeSection, items: Collection}> */
    public function build(?User $user, ?int $cityId = null): Collection
    {
        return HomeSection::live()->with('items')->get()
            ->map(fn (HomeSection $section) => ['section' => $section, 'items' => $this->resolve($section, $user, $cityId)])
            ->filter(fn ($row) => $row['items']->isNotEmpty())
            ->values();
    }

    public function resolve(HomeSection $section, ?User $user, ?int $cityId): Collection
    {
        $params = $section->source_params ?? [];
        $limit = $section->max_items;

        if ($section->type === 'banner' || $section->source === 'manual' && $section->type !== 'venues') {
            return $this->manual($section);
        }

        return match ($section->type) {
            'venues' => $this->venues($section, $params, $limit, $user, $cityId),
            'categories' => Category::where('is_active', true)->whereNull('parent_id')
                ->when($params['sector_id'] ?? null, fn ($q, $id) => $q->where('sector_id', $id))
                ->orderBy('sort_order')->limit($limit)->get(),
            'sectors' => Sector::where('is_active', true)->orderBy('sort_order')->limit($limit)->get(),
            'providers' => Provider::where('status', ProviderStatus::Approved)
                ->orderByDesc('rating_avg')->limit($limit)->get(),
            default => collect(),
        };
    }

    private function venues(HomeSection $section, array $params, int $limit, ?User $user, ?int $cityId): Collection
    {
        if ($section->source === 'recommended') {
            return $this->recommendations->forUser($user, $limit, $cityId);
        }

        $query = $this->recommendations->cards(Venue::visible(), $user)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId));

        // Venues are matched to a sector / category through their active activities.
        $offering = fn (string $column, mixed $id) => $query
            ->whereHas('activities', fn ($q) => $q->where('is_active', true)->where($column, $id ?? 0))
            ->orderByDesc('is_featured')->orderByDesc('rating_avg');

        match ($section->source) {
            'manual' => $query->whereIn('id', $section->items->where('link_type', 'venue')->pluck('link_value')),
            'featured' => $query->where('is_featured', true)->orderByDesc('rating_avg'),
            'top_rated' => $query->orderByDesc('rating_avg')->orderByDesc('rating_count'),
            'popular' => $query->withCount('orders')->orderByDesc('orders_count'),
            'category' => $offering('category_id', $params['category_id'] ?? null),
            'sector' => $offering('sector_id', $params['sector_id'] ?? null),
            // "upcoming" belonged to dated events; sections still set to it list the newest venues.
            default => $query->latest('published_at'),
        };

        return $query->limit($limit)->get();
    }

    private function manual(HomeSection $section): Collection
    {
        return $section->items->map(fn (HomeSectionItem $item) => $item);
    }
}
