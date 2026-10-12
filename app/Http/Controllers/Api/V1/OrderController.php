<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\RefundRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\RefundResource;
use App\Models\Order;
use App\Models\Review;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The signed-in customer's orders. Every lookup is scoped to the user. */
class OrderController extends Controller
{
    private const RELATIONS = ['venue.city', 'provider', 'bookings.activity', 'bookings.resource'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $request->user()->orders()
            ->with(self::RELATIONS)
            ->select('orders.*')
            ->selectSub(Review::selectRaw('COUNT(*) > 0')->whereColumn('reviews.order_id', 'orders.id'), 'reviews_exists')
            ->when($request->status, fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->scope === 'upcoming', fn ($q) => $q->whereHas('bookings', fn ($b) => $b->where('ends_at', '>=', now())))
            ->when($request->scope === 'past', fn ($q) => $q->whereDoesntHave('bookings', fn ($b) => $b->where('ends_at', '>=', now())))
            ->latest()
            ->paginate(min(max($request->integer('per_page', 20), 1), (int) config('farah.api.max_per_page', 50)));

        return OrderResource::collection($orders);
    }

    public function show(Request $request, int $order): OrderResource
    {
        $model = $this->find($request, $order)->load([...self::RELATIONS, 'payment', 'refunds']);
        $model->setAttribute('reviews_exists', Review::where('order_id', $model->id)->exists());

        return new OrderResource($model);
    }

    public function refundQuote(Request $request, int $order, RefundService $refunds): JsonResponse
    {
        $model = $this->find($request, $order)->load('venue', 'bookings');

        return response()->json(['data' => array_merge($refunds->policyQuote($model), [
            'refundable_amount' => $model->refundableAmount(),
            'eligible' => $model->isPaid() && $model->refundableAmount() > 0,
        ])]);
    }

    public function refund(RefundRequest $request, int $order, RefundService $refunds): JsonResponse
    {
        $model = $this->find($request, $order)->load('venue', 'bookings');
        $refund = $refunds->request($model, $request->user(), $request->input('items'), $request->input('reason'));

        return response()->json(['message' => __('api.refunds.requested'), 'data' => new RefundResource($refund)], 201);
    }

    private function find(Request $request, int $id): Order
    {
        return $request->user()->orders()->findOrFail($id);
    }
}
