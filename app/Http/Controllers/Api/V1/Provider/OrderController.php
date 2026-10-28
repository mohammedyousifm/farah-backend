<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Requests\Api\Provider\CheckInRequest;
use App\Http\Requests\Api\Provider\OfflineOrderRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\OrderResource;
use App\Services\CheckInService;
use App\Services\OrderService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Orders of the acting provider's venues: list, detail, counter bookings and arrival check-in. */
class OrderController extends ProviderController
{
    private const RELATIONS = ['venue.city', 'bookings.activity', 'bookings.resource'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date']]);

        $orders = $this->provider($request)->orders()
            ->with(self::RELATIONS)
            ->when($request->integer('venue_id'), fn ($q, $id) => $q->where('venue_id', $id))
            ->when($request->status, fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->channel, fn ($q, $c) => $q->where('channel', $c))
            ->when($request->q, fn ($q, $term) => $q->where(fn ($w) => $w->where('number', 'like', Like::contains($term))
                ->orWhere('customer_name', 'like', Like::contains($term))
                ->orWhere('customer_phone', 'like', Like::contains($term))
                ->orWhereHas('bookings', fn ($b) => $b->where('code', strtoupper($term)))))
            ->tap(fn ($q) => $this->filterDays($q, $request->date_from, $request->date_to))
            ->latest()
            ->paginate(min($request->integer('per_page', 20) ?: 20, 50));

        return OrderResource::collection($orders);
    }

    public function show(Request $request, int $order): OrderResource
    {
        $model = $this->provider($request)->orders()
            ->with([...self::RELATIONS, 'refunds'])
            ->findOrFail($order);

        return new OrderResource($model);
    }

    /**
     * Book for a customer at the counter (cash / transfer / POS): confirmed immediately.
     * Unlike the customer app, a slot that already started can still be sold until it ends.
     */
    public function offline(OfflineOrderRequest $request, OrderService $orders): JsonResponse
    {
        // Only the validated customer fields: a counter sale is never attached to an app account.
        // OrderService refuses a venue that is not live (draft, in review or suspended).
        $order = $orders->createOffline(
            $this->provider($request),
            $request->lines(),
            (array) $request->validated('customer', []),
            $request->payment_method,
            $request->user(),
        );

        return response()->json([
            'message' => __('api.orders.offline_created'),
            'data' => new OrderResource($order->load(self::RELATIONS)),
        ], 201);
    }

    /** Scan / type a booking code on arrival. Optional venue_id guards against a code of another branch. */
    public function checkIn(CheckInRequest $request, CheckInService $checkIn): JsonResponse
    {
        $booking = $checkIn->checkIn($this->provider($request), (string) $request->code, $request->integer('venue_id') ?: null, $request->user());

        return response()->json([
            'message' => __('api.bookings.checked_in'),
            'data' => new BookingResource($booking),
        ]);
    }
}
