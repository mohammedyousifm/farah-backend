<?php

namespace Tests\Feature\Services;

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\BookingSlot;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Refund;
use App\Models\WebhookEvent;
use App\Services\AvailabilityService;
use App\Services\CheckInService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\VenueService;
use Carbon\CarbonImmutable;

/** Booking checkout, confirmation, webhook and slot-hold edge cases. */
class OrderPaymentServiceTest extends ServiceTestCase
{
    public function test_confirm_is_idempotent_and_ledger_balances(): void
    {
        $order = $this->paidOrder($this->venue($this->provider()), 2);

        app(OrderService::class)->confirm($order);
        app(OrderService::class)->confirm($order);

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(1, $order->bookings()->count());
        $this->assertSame(2, BookingSlot::count());
        $this->assertSame(3, LedgerEntry::where('order_id', $order->id)->count());
        // Commission pair nets to zero across accounts; provider net = total - commission.
        $this->assertEquals(0.0, $this->ledgerSum($order, 'provider', 'commission') + $this->ledgerSum($order, 'platform', 'commission'));
        $this->assertEquals($order->provider_net, $this->ledgerSum($order, 'provider'));
        $this->assertEquals(26.09, $order->vat_amount); // 200 incl. 15% VAT
    }

    public function test_money_math_has_no_float_drift(): void
    {
        $venue = $this->venue($this->provider(), [], ['price_per_hour' => 33.33]);
        $order = $this->paidOrder($venue, 3);

        $this->assertSame('99.99', number_format($order->total, 2, '.', ''));
        $this->assertEquals(10.00, $order->commission_amount);
        $this->assertEquals(89.99, $order->provider_net);
    }

    public function test_half_hour_slots_are_priced_from_the_hourly_rate(): void
    {
        $venue = $this->venue($this->provider(), [], ['price_per_hour' => 75, 'slot_minutes' => 30, 'max_slots' => 6]);
        $order = app(OrderService::class)->createOnline($this->customer(), [$this->line($venue, 3, $this->slot(3, '20:30'))]);
        $booking = $order->bookings->first();

        $this->assertEquals(37.5, $booking->unit_price);
        $this->assertEquals(112.5, $order->total);
        $this->assertSame(90, (int) $booking->starts_at->diffInMinutes($booking->ends_at));
        $this->assertSame(3, BookingSlot::count());
    }

    public function test_one_order_can_hold_several_tables_and_times(): void
    {
        $venue = $this->venue($this->provider());
        [$first, $second] = $this->activity($venue)->resources->all();
        $orders = app(OrderService::class);

        $order = $orders->createOnline($this->customer(), [
            $this->line($venue, 2, null, $first->id),
            $this->line($venue, 1, $this->slot(3, '21:00'), $second->id),
        ]);

        $this->assertSame(2, $order->bookings->count());
        $this->assertEquals(300.0, $order->total);
        $this->assertSame(3, BookingSlot::count());
        $this->assertSame(2, $order->bookings->pluck('code')->unique()->count()); // one check-in code per booking

        // The same table twice in one order: the whole order is refused, nothing is left held.
        $this->assertBusiness('slot_taken', fn () => $orders->createOnline($this->customer('+966502222222'), [
            $this->line($venue, 2, $this->slot(4), $first->id),
            $this->line($venue, 1, $this->slot(4, '21:00'), $first->id),
        ]));
        $this->assertSame(1, Order::count());
        $this->assertSame(3, BookingSlot::count());
    }

    public function test_late_payment_after_timeout_takes_the_slots_again(): void
    {
        $venue = $this->venue($this->provider());
        [$order, $payment] = $this->checkout($this->customer(), $venue, 3);
        $resourceId = $order->bookings()->first()->resource_id;

        $this->travel(20)->minutes();
        $this->assertSame(1, app(OrderService::class)->expireStale());
        $this->assertSame(0, BookingSlot::count());
        $this->assertSame(0, app(OrderService::class)->expireStale()); // idempotent

        $this->assertTrue($this->signedWebhook($payment, 'evt_late'));

        $booking = $order->bookings()->first();
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertNull($booking->cancelled_at);
        $this->assertSame($resourceId, $booking->resource_id); // the very same table
        $this->assertSame(3, BookingSlot::where('booking_id', $booking->id)->count());
    }

    public function test_late_payment_when_the_slots_were_taken_is_refunded(): void
    {
        $venue = $this->venue($this->provider(), [], [], 1); // a single table
        [$order, $payment] = $this->checkout($this->customer(), $venue, 3);

        $this->travel(20)->minutes();
        app(OrderService::class)->expireStale();
        $other = $this->paidOrder($venue, 3); // someone else booked the same table and time

        $this->signedWebhook($payment, 'evt_late_taken');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('sold_out_after_payment', $order->cancel_reason);
        $this->assertEquals(300.0, $order->refunded_amount);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(BookingStatus::Expired, $order->bookings()->first()->status);
        $this->assertSame(0, LedgerEntry::where('order_id', $order->id)->count());
        // No double booking: every held slot belongs to the other customer.
        $this->assertSame(3, BookingSlot::count());
        $this->assertSame(3, BookingSlot::where('booking_id', $other->bookings->first()->id)->count());

        // Re-delivery of the same payment with a new event id does not refund twice.
        app(PaymentService::class)->markPaid($payment->fresh());
        $this->assertSame(1, Refund::where('order_id', $order->id)->count());
    }

    public function test_late_payment_with_only_one_slot_taken_holds_nothing(): void
    {
        $venue = $this->venue($this->provider(), [], [], 1);
        [$order, $payment] = $this->checkout($this->customer(), $venue, 3); // 20:00 -> 23:00

        $this->travel(20)->minutes();
        app(OrderService::class)->expireStale();
        $this->paidOrder($venue, 1, null, $this->slot(3, '22:00')); // only the last hour was taken

        $this->signedWebhook($payment, 'evt_late_partial');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(1, BookingSlot::count()); // 20:00 and 21:00 were not left half-held
    }

    public function test_late_payment_for_a_suspended_venue_is_refunded(): void
    {
        $venue = $this->venue($this->provider());
        [$order, $payment] = $this->checkout($this->customer(), $venue, 1);
        app(OrderService::class)->cancelUnpaid($order, 'customer_cancelled');
        app(VenueService::class)->suspend($venue, $this->admin(), 'licence expired');

        $this->signedWebhook($payment, 'evt_suspended_venue');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(0, BookingSlot::count());
    }

    public function test_late_payment_after_the_booked_time_passed_is_refunded(): void
    {
        $venue = $this->venue($this->provider());
        [$order, $payment] = $this->checkout($this->customer(), $venue, 1, $this->slot(1));

        $this->travel(2)->days();
        app(OrderService::class)->expireStale();
        $this->signedWebhook($payment, 'evt_too_late');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(0, BookingSlot::count());
    }

    public function test_payment_after_customer_cancel_takes_the_slots_again(): void
    {
        $venue = $this->venue($this->provider());
        [$order, $payment] = $this->checkout($this->customer(), $venue, 2);
        app(OrderService::class)->cancelUnpaid($order, 'customer_cancelled');
        $this->assertSame(0, BookingSlot::count());
        $this->assertSame(BookingStatus::Cancelled, $order->bookings()->first()->status);

        $this->signedWebhook($payment, 'evt_after_cancel');

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $order->bookings()->first()->status);
        $this->assertSame(2, BookingSlot::count());
    }

    public function test_cancelling_is_refused_once_paid_and_reported_once_done(): void
    {
        $venue = $this->venue($this->provider());
        $orders = app(OrderService::class);

        [$pending] = $this->checkout($this->customer(), $venue, 1);
        $orders->cancelUnpaid($pending, 'customer_cancelled');
        $this->assertBusiness('order_already_cancelled', fn () => $orders->cancelUnpaid($pending->fresh(), 'again'));

        $paid = $this->paidOrder($venue, 1);
        $this->assertBusiness('order_paid', fn () => $orders->cancelUnpaid($paid, 'too late'));
        $this->assertSame(1, BookingSlot::count());
    }

    public function test_second_payment_for_an_already_paid_order_is_refunded(): void
    {
        $customer = $this->customer();
        $venue = $this->venue($this->provider());
        [$order, $first] = $this->checkout($customer, $venue, 1);
        $second = app(PaymentService::class)->checkout($customer, collect([$order])); // "retry" → new session

        $this->signedWebhook($second, 'evt_second');
        $this->signedWebhook($first, 'evt_first'); // the abandoned session was paid too

        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame($second->id, $order->payment_id);
        $this->assertSame(1, $order->bookings()->where('status', BookingStatus::Confirmed)->count());
        $this->assertSame(1, BookingSlot::count());
        $this->assertSame(PaymentStatus::Refunded, $first->fresh()->status);
        $this->assertSame('duplicate_payment', Refund::where('payment_id', $first->id)->value('reason'));
        $this->assertEquals(0.0, (float) $order->refunded_amount); // the order itself is untouched
    }

    public function test_forged_webhook_cannot_burn_the_genuine_event_id(): void
    {
        [$order, $payment] = $this->checkout($this->customer(), $this->venue($this->provider()), 1);
        $body = json_encode(['id' => 'evt_real', 'type' => 'payment.failed', 'data' => ['reference' => $payment->reference]]);

        try {
            app(PaymentService::class)->handleWebhook($body, 'forged');
            $this->fail('forged webhook accepted');
        } catch (BusinessException $e) {
            $this->assertSame('invalid_signature', $e->errorCode);
        }

        $this->assertTrue($this->signedWebhook($payment, 'evt_real'));
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(1, WebhookEvent::where('event_id', 'evt_real')->count());
    }

    public function test_empty_webhook_secret_rejects_everything(): void
    {
        config(['farah.integrations.payment.webhook_secret' => '']);
        [, $payment] = $this->checkout($this->customer(), $this->venue($this->provider()), 1);

        $this->expectExceptionObject(new BusinessException('Invalid signature', 'invalid_signature', 401));
        $this->signedWebhook($payment, 'evt_nosecret');
    }

    public function test_amount_or_currency_mismatch_is_not_confirmed(): void
    {
        [$order, $payment] = $this->checkout($this->customer(), $this->venue($this->provider()), 2);

        try {
            $this->signedWebhook($payment, 'evt_short', ['amount' => 1.00, 'currency' => 'SAR']);
            $this->fail('mismatch accepted');
        } catch (BusinessException $e) {
            $this->assertSame('amount_mismatch', $e->errorCode);
        }
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
        $this->assertNotNull(WebhookEvent::where('event_id', 'evt_short')->value('error'));

        try {
            $this->signedWebhook($payment, 'evt_usd', ['amount' => 200, 'currency' => 'USD']);
            $this->fail('currency mismatch accepted');
        } catch (BusinessException $e) {
            $this->assertSame('amount_mismatch', $e->errorCode);
        }

        $this->assertTrue($this->signedWebhook($payment, 'evt_ok', ['amount' => 200, 'currency' => 'SAR']));
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    public function test_failed_webhook_after_paid_is_ignored(): void
    {
        $order = $this->paidOrder($this->venue($this->provider()), 1);
        $payment = $order->payment;

        $this->signedWebhook($payment, 'evt_fail_after', [], 'payment.failed');

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(1, BookingSlot::count()); // the slot stays held
    }

    public function test_booking_rules(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider, [], [], 6);
        $activity = $this->activity($venue);
        $other = $this->venue($provider);
        $service = app(OrderService::class);
        $customer = $this->customer();

        $this->assertBusiness('booking_empty', fn () => $service->createOnline($customer, []));
        $this->assertBusiness('max_per_order', fn () => $service->createOnline($customer,
            array_map(fn ($r) => $this->line($venue, 1, null, $r->id), $activity->resources->take(5)->all())));
        $this->assertBusiness('one_venue_per_order', fn () => $service->createOnline($customer, [$this->line($venue, 1), $this->line($other, 1)]));
        $this->assertBusiness('activity_unavailable', fn () => $service->createOnline($customer, [['activity_id' => 999999] + $this->line($venue, 1)]));
        // A table of another venue, or one taken out of service.
        $this->assertBusiness('resource_unavailable', fn () => $service->createOnline($customer,
            [$this->line($venue, 1, null, $this->activity($other)->resources->first()->id)]));
        $activity->resources->first()->update(['is_active' => false]);
        $this->assertBusiness('resource_unavailable', fn () => $service->createOnline($customer,
            [$this->line($venue, 1, null, $activity->resources->first()->id)]));
        $this->assertSame(0, Order::count()); // every failed attempt rolled back
        $this->assertSame(0, BookingSlot::count());

        // Activity switched off by the provider.
        $activity->update(['is_active' => false]);
        $this->assertBusiness('activity_unavailable', fn () => $service->createOnline($customer, [$this->line($venue, 1)]));

        // Suspended provider: its venues are not bookable.
        $activity->update(['is_active' => true]);
        $provider->update(['status' => 'suspended']);
        $this->assertBusiness('not_bookable', fn () => $service->createOnline($customer, [$this->line($venue, 1)]));
    }

    public function test_expiry_job_releases_only_unpaid_stale_orders(): void
    {
        $venue = $this->venue($this->provider());
        [$stale] = $this->checkout($this->customer(), $venue, 2);
        $paid = $this->paidOrder($venue, 3);

        $this->travel(20)->minutes();
        [$fresh] = $this->checkout($this->customer('+966509998877'), $venue, 1);

        $this->assertSame(1, app(OrderService::class)->expireStale());
        $this->assertSame(OrderStatus::Expired, $stale->fresh()->status);
        $this->assertSame(OrderStatus::Confirmed, $paid->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $fresh->fresh()->status);
        $this->assertSame(4, BookingSlot::count());

        // The released table can be booked again right away.
        $again = app(OrderService::class)->createOnline($this->customer('+966507776655'),
            [$this->line($venue, 2, null, $stale->bookings()->first()->resource_id)]);
        $this->assertSame(OrderStatus::PendingPayment, $again->status);
    }

    public function test_counter_booking_is_confirmed_at_once_and_may_start_in_the_running_slot(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $service = app(OrderService::class);
        $this->travelTo(CarbonImmutable::now('Asia/Riyadh')->addDay()->setTime(20, 30));
        $running = $this->slot(0, '20:00');

        // The 20:00 slot is already running: closed for the app, open for the counter.
        $this->assertBusiness('slot_in_past', fn () => $service->createOnline($this->customer(), [$this->line($venue, 1, $running)]));

        $order = $service->createOffline($provider, [$this->line($venue, 2, $running)], ['name' => 'Walk-in', 'phone' => '+966500009999'], 'cash', $provider->owner);

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame('offline', $order->channel);
        $this->assertSame(BookingStatus::Confirmed, $order->bookings->first()->status);
        $this->assertSame(2, BookingSlot::count());
        $this->assertEquals(20.0, $order->commission_amount);
        // Paid to the provider directly: only the commission it owes is recorded.
        $this->assertSame(0, LedgerEntry::where('order_id', $order->id)->where('type', 'sale')->count());
        $this->assertEquals(-20.0, $this->ledgerSum($order, 'provider'));

        // An hour that is completely over stays closed, and another provider cannot book here.
        $this->assertBusiness('slot_in_past', fn () => $service->createOffline($provider, [$this->line($venue, 1, $this->slot(0, '19:00'))], [], 'cash', $provider->owner));
        $stranger = $this->provider(null, 'Other');
        $this->assertBusiness('forbidden', fn () => $service->createOffline($stranger, [$this->line($venue, 1, $this->slot(1))], [], 'cash', $stranger->owner));
    }

    public function test_blocked_slots_cannot_be_booked_until_unblocked(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider, [], [], 1);
        $table = $this->activity($venue)->resources->first();
        $availability = app(AvailabilityService::class);
        $orders = app(OrderService::class);
        $customer = $this->customer();

        $this->assertSame(2, $availability->block($table, $this->slot(), 2, 'maintenance', $provider->owner));
        $this->assertBusiness('slot_taken', fn () => $orders->createOnline($customer, [$this->line($venue, 1)]));
        $this->assertBusiness('slot_taken', fn () => $availability->block($table, $this->slot(3, '21:00'), 1, null, $provider->owner));

        $day = $availability->forDate($this->activity($venue), $this->slot()->setTimezone('Asia/Riyadh')->format('Y-m-d'));
        $this->assertSame(['booked', 'booked', 'available'], array_slice(array_column($day['resources'][0]['slots'], 'status'), 10, 3));

        $availability->unblock(BookingSlot::orderBy('starts_at')->first());
        $order = $orders->createOnline($customer, [$this->line($venue, 1)]);

        // A slot held by a booking is not a block and cannot be removed this way.
        $this->assertBusiness('slot_taken', fn () => $availability->unblock($order->bookings->first()->heldSlots()->first()));
        $this->assertSame(2, BookingSlot::count());
    }

    public function test_venue_suspension_stops_new_bookings_but_keeps_paid_ones(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $paid = $this->paidOrder($venue, 2);
        [$pending, $payment] = $this->checkout($this->customer('+966508887766'), $venue, 1, $this->slot(4));

        app(VenueService::class)->suspend($venue, $this->admin(), 'safety inspection');

        $this->assertBusiness('not_bookable', fn () => app(OrderService::class)->createOnline($this->customer('+966507776655'), [$this->line($venue, 1, $this->slot(5))]));

        // Already paid: still valid and can be checked in on arrival.
        $this->assertSame(OrderStatus::Confirmed, $paid->fresh()->status);
        $checked = app(CheckInService::class)->checkIn($provider, $paid->bookings->first()->code, $venue->id, $provider->owner);
        $this->assertSame(BookingStatus::CheckedIn, $checked->status);

        // A hold made before the suspension can still be paid while it lasts.
        $this->signedWebhook($payment, 'evt_held_before_suspension');
        $this->assertSame(OrderStatus::Confirmed, $pending->fresh()->status);
    }

    public function test_check_in_rules(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $other = $this->venue($provider);
        $checkIn = app(CheckInService::class);
        $by = $provider->owner;

        [$unpaid] = $this->checkout($this->customer(), $venue, 1, $this->slot(2));
        $paid = $this->paidOrder($venue, 1, null, $this->slot(1));
        $code = $paid->bookings->first()->code;

        $this->assertBusiness('booking_not_found', fn () => $checkIn->checkIn($provider, 'NOPE-0000', null, $by));
        $this->assertBusiness('booking_not_found', fn () => $checkIn->checkIn($provider, $code, $other->id, $by)); // wrong branch
        $this->assertBusiness('booking_not_found', fn () => $checkIn->checkIn($this->provider(null, 'Other'), $code, null, $by));
        $this->assertBusiness('booking_cancelled', fn () => $checkIn->checkIn($provider, $unpaid->bookings()->first()->code, null, $by)); // not paid yet

        $this->travel(3)->days();
        $this->assertBusiness('booking_finished', fn () => $checkIn->checkIn($provider, $code, null, $by));
    }
}
