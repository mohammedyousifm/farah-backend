<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\ReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
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

        return response()->json(['message' => __('api.reviews.created'), 'data' => new ReviewResource($review->load('user'))], 201);
    }
}
