<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\VenueStatus;
use App\Exceptions\BusinessException;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Resource;
use App\Models\Setting;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\SystemNotification;
use App\Services\Integrations\Contracts\AccountingClient;
use App\Services\Integrations\IntegrationLogger;
use App\Support\DocNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Bookings and the order that pays for them.
 *
 * A booking reserves one resource (table, lane, ...) for a run of consecutive time slots.
 * Each held slot is a row in booking_slots with a unique (resource_id, starts_at) key,
 * so two customers can never hold the same slot: the second insert fails.
 */
class OrderService
{
    public function __construct(
        private CommissionService $commissions,
        private LedgerService $ledger,
        private AvailabilityService $availability,
    ) {}

    /**
     * Hold the requested slots and create one pending order for them.
     * All lines must belong to the same venue.
     *
     * @param  array<int, array{activity_id: int, resource_id?: ?int, starts_at: string, slots: int}>  $lines
     */
    public function createOnline(User $user, array $lines): Order
    {
        return DB::transaction(fn () => $this->buildOrder($lines, [
            'user_id' => $user->id,
            'channel' => 'online',
            'status' => OrderStatus::PendingPayment,
            'customer_name' => $user->name,
            'customer_phone' => $user->phone,
            'customer_email' => $user->email,
            'expires_at' => now()->addMinutes((int) Setting::get('order_payment_timeout_minutes')),
        ]));
    }

    /**
     * Provider books for a customer at the counter (cash / transfer / POS). Confirmed immediately.
     * A counter sale belongs to no app account: the customer is just a name and a phone.
     *
     * @param  array{name?: ?string, phone?: ?string, email?: ?string, notes?: ?string}  $customer
     */
    public function createOffline(Provider $provider, array $lines, array $customer, string $method, User $by): Order
    {
        $order = DB::transaction(fn () => $this->buildOrder($lines, [
            'user_id' => null,
            'channel' => 'offline',
            'status' => OrderStatus::PendingPayment,
            'customer_name' => $customer['name'] ?? null,
            'customer_phone' => $customer['phone'] ?? null,
            'customer_email' => $customer['email'] ?? null,
            'offline_payment_method' => $method,
            'notes' => $customer['notes'] ?? null,
            'created_by' => $by->id,
        ], $provider));

        return $this->confirm($order);
    }

    /**
     * Payment captured (or counter booking recorded): snapshot commission, confirm the
     * bookings, write ledger entries and notify both sides. Idempotent and safe against
     * concurrent webhooks / the expiry job (the order row is locked).
     *
     * A payment that arrives after the hold was released (payment window ended) takes the
     * same slots again if they are still free, otherwise a BusinessException is thrown
     * and the caller refunds the payment.
     * When $payment is given and the order was already paid by another payment,
     * a BusinessException with code "already_paid" is thrown (duplicate payment).
     */
    public function confirm(Order $order, ?Payment $payment = null): Order
    {
        $confirmed = DB::transaction(function () use ($order, $payment) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPaid()) {
                if ($payment && $locked->payment_id && $locked->payment_id !== $payment->id) {
                    throw new BusinessException(__('api.payments.already_paid'), 'already_paid');
                }

                return null; // idempotent
            }

            if (! in_array($locked->status, [OrderStatus::PendingPayment, OrderStatus::Expired, OrderStatus::Cancelled], true)) {
                throw new BusinessException(__('api.payments.order_not_payable'), 'order_not_payable');
            }

            if ($locked->status !== OrderStatus::PendingPayment) {
                $this->reserveAgain($locked);
            }

            $commission = $this->commissions->calculate($locked, now());

            $locked->update([
                'status' => OrderStatus::Confirmed,
                'confirmed_at' => now(),
                'expires_at' => null,
                'cancelled_at' => null,
                'cancel_reason' => null,
                'payment_id' => $payment?->id ?? $locked->payment_id,
                'commission_rule_id' => $commission['rule']?->id,
                'commission_rate' => $commission['rate'],
                'commission_fixed' => $commission['fixed'],
                'commission_amount' => $commission['amount'],
                'provider_net' => round($locked->total - $commission['amount'], 2),
            ]);

            $locked->bookings()->update(['status' => BookingStatus::Confirmed, 'cancelled_at' => null]);

            $this->ledger->recordOrder($locked);
            app(InvoiceService::class)->issue($locked);

            return $locked;
        });

        if (! $confirmed) {
            return $order->fresh(['bookings']);
        }

        $this->syncAccounting($confirmed);

        $params = ['number' => $confirmed->number, 'venue' => $confirmed->venue->name_ar];
        if ($confirmed->user) {
            SystemNotification::send($confirmed->user, 'order_confirmed', $params, ['order_id' => $confirmed->id]);
        }
        foreach ($confirmed->provider->users()->wherePivot('is_active', true)->get() as $member) {
            SystemNotification::send($member, 'new_order', $params, ['order_id' => $confirmed->id]);
        }

        return $confirmed->fresh(['bookings']);
    }

    /**
     * The payment window of an unpaid order ended: release it and free its slots (row-locked against a
     * concurrent payment). Nobody cancels an order by hand. False when it was paid or released meanwhile.
     */
    public function expire(Order $order): bool
    {
        $expired = DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::PendingPayment) {
                return false;
            }

            $this->releaseBookings($locked->bookings, BookingStatus::Expired);
            $locked->update(['status' => OrderStatus::Expired, 'cancelled_at' => now(), 'cancel_reason' => 'payment_timeout']);

            return true;
        });

        $order->refresh();

        return $expired;
    }

    /** Scheduler: drop holds whose payment window passed. */
    public function expireStale(): int
    {
        $count = 0;
        Order::where('status', OrderStatus::PendingPayment)
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->each(function (Order $order) use (&$count) {
                if ($this->expire($order)) {
                    $count++;
                }
            });

        return $count;
    }

    /** Scheduler: bookings whose time passed, and orders with nothing left to play, become completed. */
    public function completeFinished(): int
    {
        Booking::whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn])
            ->where('ends_at', '<', now())
            ->update(['status' => BookingStatus::Completed]);

        return Order::where('status', OrderStatus::Confirmed)
            ->whereDoesntHave('bookings', fn ($q) => $q->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn]))
            ->update(['status' => OrderStatus::Completed, 'completed_at' => now()]);
    }

    /**
     * Scheduler: once every booking of an order has ended, ask the customer to rate the visit. Once per order,
     * never for an order that is already rated, was cancelled, or was sold at the counter (no app account).
     * Only visits that ended in the last day are asked about, so nothing old is dug up.
     */
    public function promptReviews(): int
    {
        $due = Order::whereNull('review_prompted_at')
            ->whereNotNull('user_id')
            ->whereIn('status', [OrderStatus::Confirmed, OrderStatus::Completed, OrderStatus::PartiallyRefunded])
            ->whereHas('bookings', fn ($q) => $q->whereIn('status', [BookingStatus::CheckedIn, BookingStatus::Completed])
                ->whereBetween('ends_at', [now()->subDay(), now()]))
            // Still something to play: wait until the last booked hour is over.
            ->whereDoesntHave('bookings', fn ($q) => $q->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn])->where('ends_at', '>', now()))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('reviews')->whereColumn('reviews.order_id', 'orders.id'))
            ->with(['user', 'venue', 'bookings'])
            ->limit(200)
            ->get();

        $sent = 0;
        foreach ($due as $order) {
            // Claimed first, so two runs at the same moment can never ask twice.
            if (Order::whereKey($order->id)->whereNull('review_prompted_at')->update(['review_prompted_at' => now()]) === 0 || ! $order->user) {
                continue;
            }

            SystemNotification::send($order->user, 'review_prompt', ['venue' => $order->venue?->tr('name') ?? ''], [
                'action' => 'review',
                'order_id' => $order->id,
                'booking_id' => $order->bookings->first()?->id,
                'venue_id' => $order->venue_id,
            ]);
            $sent++;
        }

        return $sent;
    }

    public function syncAccounting(Order $order): void
    {
        $client = app(AccountingClient::class);
        $ref = IntegrationLogger::call('accounting', 'push_invoice', ['order' => $order->number], fn () => $client->pushInvoice($order));

        if ($ref) {
            $order->update(['accounting_ref' => $ref, 'accounting_synced_at' => now()]);
        }
    }

    /**
     * Used by refunds and expiry: mark bookings cancelled (or expired) and put their slots back on sale.
     *
     * @param  iterable<Booking>  $bookings
     */
    public function releaseBookings(iterable $bookings, BookingStatus $status = BookingStatus::Cancelled): void
    {
        foreach ($bookings as $booking) {
            if (! $booking->isActive()) {
                continue;
            }
            BookingSlot::where('booking_id', $booking->id)->delete();
            $booking->update(['status' => $status, 'cancelled_at' => now()]);
        }
    }

    /**
     * Validate the lines, hold the slots and create the order (prices are VAT-inclusive).
     * Must run inside a transaction.
     *
     * @param  ?Provider  $counter  set for a counter booking: the venue must be this provider's, and a slot in progress is allowed
     */
    private function buildOrder(array $lines, array $attributes, ?Provider $counter = null): Order
    {
        if ($lines === []) {
            throw new BusinessException(__('api.bookings.empty'), 'booking_empty');
        }

        $max = (int) Setting::get('max_bookings_per_order');
        if (count($lines) > $max) {
            throw new BusinessException(__('api.bookings.max_per_order', ['max' => $max]), 'max_per_order');
        }

        $activities = Activity::with(['venue.provider', 'venue.hours', 'resources'])
            ->whereIn('id', array_column($lines, 'activity_id'))
            ->get()
            ->keyBy('id');

        $venue = null;
        foreach ($lines as $line) {
            $activity = $activities[$line['activity_id']] ?? null;
            if (! $activity || ! $activity->is_active || ! $activity->venue || $activity->venue->trashed()) {
                throw new BusinessException(__('api.bookings.activity_unavailable'), 'activity_unavailable');
            }
            $venue ??= $activity->venue;
            if ($activity->venue_id !== $venue->id) {
                throw new BusinessException(__('api.bookings.one_venue'), 'one_venue_per_order');
            }
        }

        if ($counter) {
            if ($venue->provider_id !== $counter->id) {
                throw new BusinessException(__('api.orders.not_your_venue'), 'forbidden', 403);
            }
            // The counter only sells a venue that is live: not a draft, one in review, or a suspended one.
            if ($venue->status !== VenueStatus::Published) {
                throw new BusinessException(__('api.bookings.not_bookable'), 'not_bookable');
            }
        } elseif (! $venue->isBookable()) {
            throw new BusinessException(__('api.bookings.not_bookable'), 'not_bookable');
        }

        $order = Order::create(array_merge($attributes, [
            'number' => DocNumber::make('ORD', 'orders'),
            'provider_id' => $venue->provider_id,
            'venue_id' => $venue->id,
            'subtotal' => 0,
            'discount' => 0,
            'vat_amount' => 0,
            'total' => 0,
            'currency' => config('farah.currency'),
        ]));

        $total = 0;
        foreach ($lines as $line) {
            $activity = $activities[$line['activity_id']];
            $starts = $this->availability->assertRun(
                $activity,
                CarbonImmutable::parse($line['starts_at']),
                (int) $line['slots'],
                walkIn: $counter !== null,
            );

            $booking = $this->hold($order, $activity, $line['resource_id'] ?? null, $starts);
            $total += $booking->total;
        }

        $vatRate = (float) Setting::get('vat_rate');
        $total = round($total, 2);

        $order->update([
            'subtotal' => $total,
            'vat_amount' => round($total - $total / (1 + $vatRate / 100), 2),
            'total' => $total,
        ]);

        return $order->load('bookings', 'venue', 'provider');
    }

    /**
     * Reserve the slots on the requested resource, or on the first free one when none was chosen.
     *
     * @param  array<int, CarbonImmutable>  $starts
     */
    private function hold(Order $order, Activity $activity, ?int $resourceId, array $starts): Booking
    {
        $candidates = $activity->resources->where('is_active', true);
        if ($resourceId) {
            $candidates = $candidates->where('id', $resourceId);
            if ($candidates->isEmpty()) {
                throw new BusinessException(__('api.bookings.resource_unavailable'), 'resource_unavailable');
            }
        }

        // Skip resources that are visibly taken; the unique key still decides under concurrency.
        $busy = BookingSlot::whereIn('resource_id', $candidates->pluck('id'))
            ->whereIn('starts_at', $starts)
            ->pluck('resource_id')
            ->unique();

        foreach ($candidates->whereNotIn('id', $busy) as $resource) {
            try {
                // Nested transaction = savepoint: a lost race rolls back only this attempt.
                return DB::transaction(fn () => $this->createBooking($order, $activity, $resource, $starts));
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new BusinessException(__('api.bookings.slot_taken'), 'slot_taken', 409);
    }

    /** @param  array<int, CarbonImmutable>  $starts */
    private function createBooking(Order $order, Activity $activity, Resource $resource, array $starts): Booking
    {
        $unit = $activity->slotPrice();

        $booking = Booking::create([
            'code' => DocNumber::bookingCode(),
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'provider_id' => $order->provider_id,
            'venue_id' => $order->venue_id,
            'activity_id' => $activity->id,
            'resource_id' => $resource->id,
            'name' => $activity->name_ar.' - '.$resource->name_ar,
            'starts_at' => $starts[0],
            'ends_at' => end($starts)->addMinutes($activity->slot_minutes),
            'slot_minutes' => $activity->slot_minutes,
            'slots' => count($starts),
            'unit_price' => $unit,
            'total' => round($unit * count($starts), 2),
            'status' => BookingStatus::Pending,
        ]);

        $this->insertSlots($booking);

        return $booking;
    }

    private function insertSlots(Booking $booking): void
    {
        for ($i = 0; $i < $booking->slots; $i++) {
            BookingSlot::create([
                'resource_id' => $booking->resource_id,
                'booking_id' => $booking->id,
                'starts_at' => $booking->starts_at->copy()->addMinutes($booking->slot_minutes * $i),
            ]);
        }
    }

    /** Paid after the hold was released: only if the venue still takes bookings and the same slots are free. */
    private function reserveAgain(Order $order): void
    {
        $venue = Venue::with('provider')->find($order->venue_id);

        if (! $venue || ! $venue->isBookable()) {
            throw new BusinessException(__('api.bookings.not_bookable'), 'not_bookable');
        }

        try {
            DB::transaction(function () use ($order) {
                foreach ($order->bookings as $booking) {
                    if ($booking->ends_at->isPast()) {
                        throw new BusinessException(__('api.bookings.in_the_past'), 'slot_in_past');
                    }
                    $this->insertSlots($booking);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessException(__('api.bookings.slot_taken'), 'slot_taken', 409);
        }
    }
}
