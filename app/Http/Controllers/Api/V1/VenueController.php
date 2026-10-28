<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlanFeature;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\AvailabilityRequest;
use App\Http\Requests\Api\Customer\VenueIndexRequest;
use App\Http\Resources\ProviderProfileResource;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\VenueCardResource;
use App\Http\Resources\VenueResource;
use App\Models\Activity;
use App\Models\Provider;
use App\Models\Venue;
use App\Services\AvailabilityService;
use App\Services\RecommendationService;
use App\Services\SubscriptionService;
use App\Support\Geo;
use App\Support\Like;
use App\Support\Promoted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Customer-facing discovery: search, filters, venue details, free slots, reviews, recommendations. */
class VenueController extends Controller
{
    public function index(VenueIndexRequest $request): AnonymousResourceCollection
    {
        $query = $this->withFavorite(Venue::visible()->with(VenueCardResource::RELATIONS), $request);

        if ($q = trim((string) $request->q)) {
            $query->where(fn ($w) => $w->where('name_ar', 'like', Like::contains($q))
                ->orWhere('name_en', 'like', Like::contains($q))
                ->orWhere('summary_ar', 'like', Like::contains($q))
                ->orWhere('address', 'like', Like::contains($q))
                ->orWhereHas('city', fn ($c) => $c->where('name_ar', 'like', Like::contains($q))->orWhere('name_en', 'like', Like::contains($q)))
                ->orWhereHas('activities', fn ($a) => $a->where('is_active', true)
                    ->where(fn ($n) => $n->where('name_ar', 'like', Like::contains($q))->orWhere('name_en', 'like', Like::contains($q)))));
        }

        // A venue has no sector of its own: it matches through the activities it offers.
        if ($request->sector_id || $request->category_id) {
            $query->whereHas('activities', fn ($a) => $a->where('is_active', true)
                ->when($request->sector_id, fn ($w, $v) => $w->where('sector_id', $v))
                ->when($request->category_id, fn ($w, $v) => $w->where(fn ($c) => $c->where('category_id', $v)
                    ->orWhereIn('category_id', fn ($sub) => $sub->select('id')->from('categories')->where('parent_id', $v)))));
        }

        $query
            ->when($request->city_id, fn ($q, $v) => $q->where('city_id', $v))
            ->when($request->provider_id, fn ($q, $v) => $q->where('provider_id', $v))
            ->when($request->filled('price_min'), fn ($q) => $q->where('min_price', '>=', $request->price_min))
            ->when($request->filled('price_max'), fn ($q) => $q->where('min_price', '<=', $request->price_max))
            ->when($request->audience, fn ($q, $v) => $q->whereIn('audience', [$v, 'all']))
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true));

        // The phone's point if it sent one, else the location saved in the customer's profile.
        $point = $request->filled('lat') && $request->filled('lng')
            ? ['lat' => (float) $request->lat, 'lng' => (float) $request->lng]
            : $request->user('sanctum')?->location();
        $hasGeo = $point !== null;
        if ($hasGeo) {
            Geo::withDistance($query, $point['lat'], $point['lng'], $request->filled('radius') ? (float) $request->radius : null);
        }

        match ($request->input('sort', $hasGeo ? 'nearest' : 'recommended')) {
            'latest' => $query->latest('published_at'),
            'price' => $query->orderBy('min_price'),
            'rating' => $query->orderByDesc('rating_avg')->orderByDesc('rating_count'),
            'nearest' => $hasGeo ? Geo::orderByDistance($query) : $this->recommended($query),
            default => $this->recommended($query),
        };

        return VenueCardResource::collection($query->orderBy('venues.id')->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function show(Request $request, string $venue): VenueResource
    {
        $model = $this->withFavorite(Venue::visible(), $request)
            ->where(fn ($q) => ctype_digit($venue) ? $q->whereKey($venue) : $q->where('slug', $venue))
            ->with(VenueResource::RELATIONS)
            ->firstOrFail();

        Venue::whereKey($model->id)->toBase()->increment('views_count');

        return new VenueResource($model);
    }

    public function reviews(Request $request, int $venue): AnonymousResourceCollection
    {
        $model = Venue::visible()->findOrFail($venue);
        $reviews = $model->reviews()->where('status', 'published')->with('user')->latest()->paginate(20);

        return ReviewResource::collection($reviews)->additional(['meta' => [
            'rating_avg' => $model->rating_avg,
            'rating_count' => $model->rating_count,
            'distribution' => $model->reviews()->where('status', 'published')
                ->selectRaw('rating, COUNT(*) as total')->groupBy('rating')->pluck('total', 'rating'),
        ]]);
    }

    public function similar(Request $request, int $venue, RecommendationService $recommendations): AnonymousResourceCollection
    {
        $model = Venue::visible()->findOrFail($venue);

        return VenueCardResource::collection(
            $recommendations->forUser($request->user('sanctum'), min($request->integer('limit', 10) ?: 10, 30), $model->city_id, $model)
        );
    }

    public function recommendations(Request $request, RecommendationService $recommendations): AnonymousResourceCollection
    {
        $user = $request->user('sanctum');

        return VenueCardResource::collection(
            $recommendations->forUser($user, min($request->integer('limit', 10) ?: 10, 30), $request->integer('city_id') ?: $user?->city_id)
        );
    }

    /**
     * Every slot of the activity on one day, per resource (table, lane, ...), with its state:
     * available | booked | past | closed (beyond the venue's advance booking window).
     */
    public function availability(AvailabilityRequest $request, int $activity, AvailabilityService $availability): JsonResponse
    {
        $model = Activity::where('is_active', true)
            ->whereHas('venue', fn ($q) => $q->withoutTrashed()->visible())
            ->with(['venue.hours', 'resources'])
            ->findOrFail($activity);

        $timezone = AvailabilityService::timezone();
        $day = $availability->forDate($model, $request->input('date') ?: CarbonImmutable::now($timezone)->format('Y-m-d'));

        return response()->json(['data' => [
            'activity_id' => $model->id,
            'venue_id' => $model->venue_id,
            'date' => $day['date'],
            'timezone' => $day['timezone'],
            'is_open' => $day['is_open'],
            'opens_at' => $day['opens_at'],
            'closes_at' => $day['closes_at'],
            'slot_minutes' => $day['slot_minutes'],
            'slot_price' => $day['slot_price'],
            'currency' => config('farah.currency'),
            'min_slots' => $model->min_slots,
            'max_slots' => $model->max_slots,
            'resources' => collect($day['resources'])->map(fn (array $row) => [
                'id' => $row['resource']->id,
                'name' => $row['resource']->tr('name'),
                'slots' => array_map(fn (array $slot) => [
                    'starts_at' => $slot['starts_at']->toIso8601String(),
                    'ends_at' => $slot['ends_at']->toIso8601String(),
                    'time' => $slot['starts_at']->setTimezone($timezone)->format('H:i'),
                    'end_time' => $slot['ends_at']->setTimezone($timezone)->format('H:i'),
                    'status' => $slot['status'],
                ], $row['slots']),
            ])->values(),
        ]]);
    }

    public function provider(Request $request, Provider $provider): ProviderProfileResource
    {
        abort_unless($provider->isApproved(), 404);

        $provider->load(['city', 'sectors' => fn ($q) => $q->wherePivot('status', 'approved')]);
        $provider->setRelation('venues', $this->withFavorite(
            Venue::visible()->where('provider_id', $provider->id)->with(VenueCardResource::RELATIONS), $request
        )->orderByDesc('is_featured')->orderByDesc('rating_avg')->limit(50)->get());

        return new ProviderProfileResource($provider);
    }

    /** Stores whose plan includes "top of search" first, then featured, then the best rated. */
    private function recommended(Builder $query): Builder
    {
        Promoted::first($query, app(SubscriptionService::class)->providerIdsWith(PlanFeature::SearchBoost), 'venues.provider_id');

        return $query->orderByDesc('is_featured')->orderByDesc('rating_avg')->orderByDesc('rating_count');
    }

    private function withFavorite(Builder $query, Request $request): Builder
    {
        $userId = $request->user('sanctum')?->id;

        return $userId
            ? $query->withExists(['favoritedBy as is_favorite' => fn ($q) => $q->where('users.id', $userId)])
            : $query;
    }
}
