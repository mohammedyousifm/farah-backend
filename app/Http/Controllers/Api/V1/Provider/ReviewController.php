<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Requests\Api\Provider\ReviewReplyRequest;
use App\Http\Resources\ReviewResource;
use App\Notifications\SystemNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Customer reviews of the provider's venues, with a public reply. */
class ReviewController extends ProviderController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $provider = $this->provider($request);

        $reviews = $provider->reviews()
            ->where('status', 'published')
            ->with(['user', 'venue'])
            ->when($request->integer('venue_id'), fn ($q, $id) => $q->where('venue_id', $id))
            ->when($request->integer('rating'), fn ($q, $r) => $q->where('rating', $r))
            ->when($request->has('replied'), fn ($q) => $request->boolean('replied') ? $q->whereNotNull('provider_reply') : $q->whereNull('provider_reply'))
            ->latest()
            ->paginate(20);

        return ReviewResource::collection($reviews)->additional(['meta' => [
            'rating_avg' => $provider->rating_avg,
            'rating_count' => $provider->rating_count,
        ]]);
    }

    public function reply(ReviewReplyRequest $request, int $review): JsonResponse
    {
        $model = $this->provider($request)->reviews()->findOrFail($review);
        $model->update(['provider_reply' => $request->reply, 'replied_at' => now()]);
        if ($model->user) {
            SystemNotification::send($model->user, 'review_replied', ['venue' => $model->venue?->tr('name') ?? ''], ['review_id' => $model->id, 'venue_id' => $model->venue_id]);
        }

        return response()->json(['message' => __('api.reviews.replied'), 'data' => new ReviewResource($model->fresh(['user', 'venue']))]);
    }
}
