<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\BookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Models\BehaviorEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Review;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Booking is direct: pick the slots → one pending order (slots held for the payment
 * window) + one payment for it. The app opens `payment.checkout_url` in a web view,
 * then polls GET /payments/{reference} until status is paid or failed.
 */
class BookingController extends Controller
{
    private const RELATIONS = ['activity', 'resource', 'venue.city', 'order'];

    /** The signed-in customer's bookings (the "My bookings" wallet). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $scope = $request->input('scope', 'upcoming');
        $finished = [BookingStatus::Completed, BookingStatus::Cancelled, BookingStatus::Expired];

        $bookings = $request->user()->bookings()
            ->with(self::RELATIONS)
            ->when($request->status, fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($scope === 'upcoming', fn ($q) => $q->whereNotIn('status', $finished)->where('ends_at', '>=', now())->orderBy('starts_at'))
            ->when($scope === 'past', fn ($q) => $q->where(fn ($w) => $w->whereIn('status', $finished)->orWhere('ends_at', '<', now()))->orderByDesc('starts_at'))
            ->when(! in_array($scope, ['upcoming', 'past'], true), fn ($q) => $q->orderByDesc('starts_at'))
            ->paginate(min(max($request->integer('per_page', 20), 1), (int) config('farah.api.max_per_page', 50)));

        // One query for the whole page: which of these orders were already rated ("rate" chip in the app).
        $rated = Review::whereIn('order_id', $bookings->pluck('order_id')->filter()->unique())->pluck('order_id')->flip();
        $bookings->each(fn ($booking) => $booking->order?->setAttribute('reviews_exists', $rated->has($booking->order_id)));

        return BookingResource::collection($bookings);
    }

    public function show(Request $request, int $booking): BookingResource
    {
        $model = $request->user()->bookings()->with(self::RELATIONS)->findOrFail($booking);
        // Lets the app offer "rate your visit" once, and say "already rated" afterwards.
        $model->order?->setAttribute('reviews_exists', Review::where('order_id', $model->order_id)->exists());

        return new BookingResource($model);
    }

    public function store(BookingRequest $request, OrderService $orders, PaymentService $payments): JsonResponse
    {
        $user = $request->user();
        $lines = $request->lines();

        // Everything happens in one transaction that first locks the customer's row, so a double tap
        // (or two devices) booking at the same moment is handled one after the other: the second
        // request finds the order the first one just created and gets it back instead of holding
        // another table for the same time (or failing with "slot taken" against its own booking).
        [$order, $payment, $created] = DB::transaction(function () use ($user, $lines, $orders, $payments) {
            User::whereKey($user->id)->lockForUpdate()->first();

            if ($duplicate = $this->recentDuplicate($user, $lines)) {
                return [...$duplicate, false];
            }

            $order = $orders->createOnline($user, $lines);

            return [$order, $payments->checkout($user, collect([$order])), true];
        });

        if ($created && $user->tracking_consent) {
            BehaviorEvent::create(['user_id' => $user->id, 'name' => 'start_booking', 'subject_type' => 'venue', 'subject_id' => $order->venue_id,
                'properties' => ['order_id' => $order->id]]);
        }

        return response()->json(['data' => [
            'order' => new OrderResource($order->fresh()->load(['bookings.activity', 'bookings.resource', 'venue.city', 'provider'])),
            'payment' => new PaymentResource($payment),
        ]], 201);
    }

    /**
     * The same booking submitted again within a few seconds (double tap / retry on a slow network):
     * return the order and payment already created instead of reserving twice.
     *
     * @param  array<int, array{activity_id: int, resource_id: ?int, starts_at: string, slots: int}>  $lines
     * @return array{0: Order, 1: Payment}|null
     */
    private function recentDuplicate(User $user, array $lines): ?array
    {
        $recent = $user->orders()
            ->where('status', OrderStatus::PendingPayment)
            ->where('channel', 'online')
            ->where('created_at', '>=', now()->subSeconds(15))
            ->whereNotNull('payment_id')
            ->with('bookings')
            ->latest('id')
            ->get();

        foreach ($recent as $order) {
            if (! $this->sameBookings($order, $lines)) {
                continue;
            }
            $payment = Payment::find($order->payment_id);
            if ($payment) {
                return [$order, $payment];
            }
        }

        return null;
    }

    /** Every line matches one booking of the order (same activity, start and length; same resource when one was chosen). */
    private function sameBookings(Order $order, array $lines): bool
    {
        $bookings = $order->bookings->all();

        if (count($bookings) !== count($lines)) {
            return false;
        }

        foreach ($lines as $line) {
            $start = CarbonImmutable::parse($line['starts_at'])->getTimestamp();

            foreach ($bookings as $key => $booking) {
                if ($booking->activity_id === $line['activity_id']
                    && $booking->slots === $line['slots']
                    && $booking->starts_at->getTimestamp() === $start
                    && ($line['resource_id'] === null || $booking->resource_id === $line['resource_id'])) {
                    unset($bookings[$key]);

                    continue 2;
                }
            }

            return false;
        }

        return true;
    }
}
