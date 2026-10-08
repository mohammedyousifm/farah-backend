<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VenueCardResource;
use App\Models\BehaviorEvent;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FavoriteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $venues = $request->user()->favorites()
            ->with(VenueCardResource::RELATIONS)
            ->orderByPivot('created_at', 'desc')
            ->paginate(20);

        $venues->getCollection()->each(fn ($v) => $v->setAttribute('is_favorite', true));

        return VenueCardResource::collection($venues);
    }

    public function store(Request $request, int $venue): JsonResponse
    {
        abort_unless(Venue::visible()->whereKey($venue)->exists(), 404);

        $user = $request->user();
        $user->favorites()->syncWithoutDetaching([$venue]);

        if ($user->tracking_consent) {
            BehaviorEvent::create(['user_id' => $user->id, 'name' => 'favorite', 'subject_type' => 'venue', 'subject_id' => $venue]);
        }

        return response()->json(['message' => __('api.saved'), 'data' => ['venue_id' => $venue, 'is_favorite' => true]], 201);
    }

    public function destroy(Request $request, int $venue): JsonResponse
    {
        $request->user()->favorites()->detach($venue);

        return response()->json(['message' => __('api.deleted'), 'data' => ['venue_id' => $venue, 'is_favorite' => false]]);
    }
}
