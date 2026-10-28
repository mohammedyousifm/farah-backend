<?php

namespace App\Services;

use App\Enums\PlanFeature;
use App\Http\Resources\VenueCardResource;
use App\Models\Activity;
use App\Models\BehaviorEvent;
use App\Models\RecommendationRule;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Rule-based recommendations (FR-AI-01/02). Each active rule contributes
 * candidate venues scored by its weight; results are merged and ranked.
 * A venue is matched through the activities it offers (their type and category).
 * Behaviour is only read for users who gave tracking consent.
 */
class RecommendationService
{
    /** @return Collection<int, Venue> */
    public function forUser(?User $user, int $limit = 10, ?int $cityId = null, ?Venue $context = null): Collection
    {
        $scores = [];
        $signals = $this->signals($user, $context);
        $cityId ??= $user?->city_id;

        foreach (RecommendationRule::where('is_active', true)->get() as $rule) {
            $params = $rule->params ?? [];
            $take = (int) ($params['limit'] ?? 20);

            $ids = match ($rule->strategy) {
                'same_category' => $signals['categories'] ? $this->offering('category_id', $signals['categories'])->limit($take)->pluck('id') : collect(),
                'same_sector' => $signals['sectors'] ? $this->offering('sector_id', $signals['sectors'])->limit($take)->pluck('id') : collect(),
                'favorites_similar' => $signals['favorite_sectors'] ? $this->offering('sector_id', $signals['favorite_sectors'])->limit($take)->pluck('id') : collect(),
                'popular_in_city' => $cityId ? $this->base()->where('city_id', $cityId)->withCount('orders')->orderByDesc('orders_count')->limit($take)->pluck('id') : collect(),
                'trending' => $this->base()->where('published_at', '>=', now()->subDays((int) ($params['days'] ?? 14)))->orderByDesc('views_count')->limit($take)->pluck('id'),
                'top_rated' => $this->base()->where('rating_count', '>=', (int) ($params['min_reviews'] ?? 3))->orderByDesc('rating_avg')->limit($take)->pluck('id'),
                // "upcoming" made sense for dated events only; old rules with it behave as "latest".
                'latest', 'upcoming' => $this->base()->latest('published_at')->limit($take)->pluck('id'),
                default => collect(),
            };

            foreach ($ids->values() as $position => $id) {
                // Earlier positions inside a rule score slightly higher.
                $scores[$id] = ($scores[$id] ?? 0) + $rule->weight * (1 - $position / max($take, 1) / 2);
            }
        }

        unset($scores[$context?->id]);

        arsort($scores);
        // Stores whose plan includes "recommendations" take the first places (up to a third of the list).
        $ids = array_slice(array_values(array_unique([...$this->promoted($cityId, $context, max(1, intdiv($limit, 3))), ...array_keys($scores)])), 0, $limit);

        if ($ids === []) {
            return $this->cards($this->base(), $user)
                ->when($context, fn ($q) => $q->whereKeyNot($context->id))
                ->orderByDesc('is_featured')->orderByDesc('rating_avg')->latest('published_at')
                ->limit($limit)->get();
        }

        return $this->cards(Venue::query(), $user)->whereIn('id', $ids)->get()
            ->sortBy(fn (Venue $v) => array_search($v->id, $ids))->values();
    }

    /** Eager loads what a venue card shows, including the user's favourite flag. */
    public function cards(Builder $query, ?User $user): Builder
    {
        return $query->with(VenueCardResource::RELATIONS)
            ->when($user, fn ($q) => $q->withExists(['favoritedBy as is_favorite' => fn ($f) => $f->where('users.id', $user->id)]));
    }

    /**
     * Visible venues of stores holding the "recommendations" plan feature, the user's city first, best rated first.
     *
     * @return array<int, int>
     */
    private function promoted(?int $cityId, ?Venue $context, int $take): array
    {
        $providers = app(SubscriptionService::class)->providerIdsWith(PlanFeature::Recommended);
        if ($providers === []) {
            return [];
        }

        return $this->base()->whereIn('provider_id', $providers)
            ->when($context, fn ($q) => $q->whereKeyNot($context->id))
            ->when($cityId, fn ($q) => $q->orderByRaw('city_id = ? desc', [$cityId]))
            ->orderByDesc('rating_avg')->limit($take)->pluck('id')->all();
    }

    private function base(): Builder
    {
        return Venue::visible();
    }

    /** Visible venues with an active activity whose $column is one of $ids. */
    private function offering(string $column, array $ids): Builder
    {
        return $this->base()
            ->whereHas('activities', fn ($q) => $q->where('is_active', true)->whereIn($column, $ids))
            ->orderByDesc('rating_avg');
    }

    private function signals(?User $user, ?Venue $context): array
    {
        $signals = ['categories' => [], 'sectors' => [], 'favorite_sectors' => []];
        $venueIds = $context ? [$context->id] : [];

        if ($user) {
            $signals['favorite_sectors'] = $this->activityColumn($user->favorites()->pluck('venues.id')->all(), 'sector_id');
            // Places the customer already booked say what they like to play.
            $venueIds = array_merge($venueIds, $user->orders()->latest('id')->limit(20)->pluck('venue_id')->all());

            if ($user->tracking_consent) {
                $viewed = BehaviorEvent::where('user_id', $user->id)
                    ->whereIn('name', ['view_venue', 'start_booking', 'purchase'])
                    ->where('subject_type', (new Venue)->getMorphClass())
                    ->where('created_at', '>=', now()->subDays(60))
                    ->latest('id')->limit(50)->pluck('subject_id');
                $venueIds = array_merge($venueIds, $viewed->all());
            }
        }

        $signals['categories'] = $this->activityColumn($venueIds, 'category_id');
        $signals['sectors'] = $this->activityColumn($venueIds, 'sector_id');

        return $signals;
    }

    /** Distinct sector / category ids of the active activities of these venues. */
    private function activityColumn(array $venueIds, string $column): array
    {
        $venueIds = array_values(array_unique(array_filter($venueIds)));

        if ($venueIds === []) {
            return [];
        }

        return Activity::whereIn('venue_id', $venueIds)->where('is_active', true)
            ->whereNotNull($column)->distinct()->pluck($column)->all();
    }
}
