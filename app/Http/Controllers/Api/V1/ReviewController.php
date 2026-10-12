<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\ReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Notifications\SystemNotification;
use Illuminate\Http\JsonResponse;

class ReviewController extends Controller
{
    public function store(ReviewRequest $request): JsonResponse
    {
        $user = $request->user();
        $order = $user->orders()->find($request->order_id);

        $reviewable = [OrderStatus::Confirmed, OrderStatus::Completed, OrderStatus::PartiallyRefunded];
        if (! $order || ! in_array($order->status, $reviewable, true)) {
            throw new BusinessException(__('api.reviews.not_allowed'), 'review_not_allowed', 403);
        }
        // A visit is rated once it has begun (or the customer was checked in), not from the sofa beforehand.
        if (! $order->bookings()->where(fn ($q) => $q->where('starts_at', '<=', now())->orWhereNotNull('checked_in_at'))->exists()) {
            throw new BusinessException(__('api.reviews.too_early'), 'review_too_early', 403);
        }
        if (Review::where('user_id', $user->id)->where('order_id', $order->id)->exists()) {
            throw new BusinessException(__('api.reviews.already_reviewed'), 'already_reviewed');
        }

        $review = Review::create([
            'user_id' => $user->id,
            'venue_id' => $order->venue_id,
            'provider_id' => $order->provider_id,
            'order_id' => $order->id,
            'rating' => $request->integer('rating'),
            'comment' => $request->comment,
            'status' => 'published',
        ]);

        foreach ($order->provider?->users()->wherePivot('is_active', true)->get() ?? [] as $member) {
            SystemNotification::send($member, 'new_review', ['rating' => $review->rating, 'venue' => $order->venue?->tr('name') ?? ''], ['review_id' => $review->id, 'venue_id' => $order->venue_id]);
        }

        return response()->json(['message' => __('api.reviews.created'), 'data' => new ReviewResource($review->load('user'))], 201);
    }
}
