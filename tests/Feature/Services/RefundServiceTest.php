<?php

namespace Tests\Feature\Services;

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\BookingSlot;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Venue;
use App\Services\CheckInService;
use App\Services\Integrations\Contracts\PaymentGateway;
use App\Services\Integrations\Drivers\FakePaymentGateway;
use App\Services\LedgerService;
use App\Services\OrderService;
use App\Services\RefundService;
use Carbon\CarbonImmutable;

class RefundServiceTest extends ServiceTestCase
{
    private function refunds(): RefundService
    {
        return app(RefundService::class);
    }

    /** One paid order holding $count tables for the same hour (one booking each). */
    private function paidTables(Venue $venue, int $count = 3, ?CarbonImmutable $startsAt = null): Order
    {
        return $this->paidLines($this->activity($venue)->resources->take($count)
            ->map(fn ($table) => $this->line($venue, 1, $startsAt, $table->id))->all());
    }

    public function test_multiple_partial_refunds_reverse_exactly_the_commission(): void
    {
        $venue = $this->venue($this->provider(), [], ['price_per_hour' => 33.33]);
        $order = $this->paidTables($venue); // 3 tables x 33.33
        [$first, $second] = $order->bookings->all();
        $admin = $this->admin();

        foreach ([[['booking_id' => $first->id]], [['booking_id' => $second->id]], null] as $i => $items) {
            $refund = $this->refunds()->request($order->fresh(['venue', 'bookings']), $order->user, $items, 'test');
            $this->assertEquals(33.33, $refund->amount);
            $this->refunds()->approve($refund, $admin);
            $this->assertSame(2 - $i, BookingSlot::count()); // only the refunded table goes back on sale
        }

        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertEquals(99.99, $order->refunded_amount);
        $this->assertEquals([3.33, 3.33, 3.34], Refund::where('order_id', $order->id)->orderBy('id')->pluck('commission_reversed')->all());
        $this->assertEquals(10.00, (float) Refund::where('order_id', $order->id)->sum('commission_reversed'));
        $this->assertEquals(0.0, $this->ledgerSum($order, 'provider'));
        $this->assertEquals(0.0, $this->ledgerSum($order, 'platform'));
        $this->assertSame(PaymentStatus::Refunded, $order->payment->status);
        $this->assertEquals(99.99, $order->payment->refunded_amount);
        $this->assertSame(3, $order->bookings()->where('status', BookingStatus::Cancelled)->count());
    }

    public function test_partial_refund_cancels_only_the_chosen_bookings(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $order = $this->paidTables($venue); // 3 x 100
        [$kept, $returned, $alsoKept] = $order->bookings->all();

        $refund = $this->refunds()->request($order, $order->user, [['booking_id' => $returned->id]], 'one friend cancelled');
        $this->assertSame('partial', $refund->type);
        $this->assertEquals(100.0, $refund->amount);
        $this->refunds()->approve($refund, $this->admin());

        $order->refresh();
        $this->assertSame(OrderStatus::PartiallyRefunded, $order->status);
        $this->assertEquals(100.0, $order->refunded_amount);
        $this->assertEquals(10.0, $refund->fresh()->commission_reversed);
        $this->assertSame(BookingStatus::Cancelled, $returned->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $kept->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $alsoKept->fresh()->status);
        $this->assertSame(0, BookingSlot::where('resource_id', $returned->resource_id)->count());

        // The freed table is bookable again; the kept ones are still used normally.
        $again = app(OrderService::class)->createOnline($this->customer(), [$this->line($venue, 1, null, $returned->resource_id)]);
        $this->assertSame(OrderStatus::PendingPayment, $again->status);
        $this->assertSame(BookingStatus::CheckedIn, app(CheckInService::class)->checkIn($provider, $kept->code, null, $provider->owner)->status);
        $this->assertBusiness('booking_cancelled', fn () => app(CheckInService::class)->checkIn($provider, $returned->code, null, $provider->owner));

        // Naming every remaining booking is a full refund of what is left.
        $rest = $this->refunds()->request($order->fresh(['venue', 'bookings']), $order->user,
            [['booking_id' => $kept->id], ['booking_id' => $alsoKept->id]], null);
        $this->assertSame('full', $rest->type);
        $this->assertEquals(200.0, $rest->amount);
    }

    public function test_refund_can_never_exceed_what_was_paid(): void
    {
        $order = $this->paidOrder($this->venue($this->provider()), 2); // 200 SAR
        $admin = $this->admin();

        $this->assertBusiness('invalid_amount', fn () => $this->refunds()->createByAdmin($order, 200.01, null, 'x', $admin));

        // A customer request is pending while an admin refunds everything...
        $request = $this->refunds()->request($order->fresh(['venue', 'bookings']), $order->user, null, 'cannot come');
        $this->refunds()->createByAdmin($order->fresh(), 200, null, 'goodwill', $admin);

        // ...so approving the old request would pay twice: refused.
        $this->assertBusiness('invalid_amount', fn () => $this->refunds()->approve($request, $admin));
        $this->assertEquals(200.0, $order->fresh()->refunded_amount);
        $this->assertEquals(200.0, $order->payment->fresh()->refunded_amount);
        $this->assertBusiness('order_not_refundable', fn () => $this->refunds()->createByAdmin($order->fresh(), 1, null, 'x', $admin));
    }

    public function test_a_refund_is_processed_once(): void
    {
        $order = $this->paidOrder($this->venue($this->provider()), 2);
        $admin = $this->admin();
        $refund = $this->refunds()->request($order->fresh(['venue', 'bookings']), $order->user, null, null);

        $this->assertBusiness('refund_pending', fn () => $this->refunds()->request($order->fresh(['venue', 'bookings']), $order->user, null, null));
        $this->refunds()->approve($refund, $admin);
        $this->assertBusiness('refund_not_pending', fn () => $this->refunds()->approve($refund->fresh(), $admin));
        $this->assertBusiness('refund_not_pending', fn () => $this->refunds()->reject($refund->fresh(), $admin, 'late'));

        $this->assertSame(RefundStatus::Processed, $refund->fresh()->status);
        $this->assertEquals(200.0, $order->payment->fresh()->refunded_amount);
        $this->assertSame(1, LedgerEntry::where('refund_id', $refund->id)->where('type', 'refund')->count());

        // Ledger writing is idempotent.
        app(LedgerService::class)->recordRefund($refund->fresh());
        $this->assertSame(3, LedgerEntry::where('refund_id', $refund->id)->count());
    }

    public function test_policy_percentage_follows_the_hours_left_before_the_booking(): void
    {
        $provider = $this->provider();
        $this->travelTo(CarbonImmutable::now('Asia/Riyadh')->addDay()->setTime(9, 0));
        $percent = function (string $policy, int $days) use ($provider): int {
            $order = $this->paidOrder($this->venue($provider, ['refund_policy' => $policy]), 1, null, $this->slot($days));

            return $this->refunds()->policyQuote($order)['percent'];
        };

        // The clock runs to the booking start (20:00): 11 h, 35 h, 83 h, 8 days away.
        $this->assertSame([50, 100], [$percent('flexible', 0), $percent('flexible', 1)]);
        $this->assertSame([0, 50, 100], [$percent('moderate', 0), $percent('moderate', 1), $percent('moderate', 3)]);
        $this->assertSame([0, 0, 50], [$percent('strict', 3), $percent('strict', 6), $percent('strict', 8)]);
        $this->assertSame(0, $percent('none', 8));

        // Nothing to give back: the request is refused instead of creating a zero refund.
        $late = $this->paidOrder($this->venue($provider, ['refund_policy' => 'moderate']), 1, null, $this->slot(0));
        $this->assertBusiness('refund_not_eligible', fn () => $this->refunds()->request($late, $late->user, null, null));

        // Once the booked hour has started the policy gives nothing, even the flexible one.
        $started = $this->paidOrder($this->venue($provider), 1, null, $this->slot(0));
        $this->travel(12)->hours();
        $this->assertSame(0, $this->refunds()->policyQuote($started->fresh(['venue', 'bookings']))['percent']);
    }

    public function test_policy_clock_runs_to_the_earliest_booking_being_given_up(): void
    {
        $venue = $this->venue($this->provider(), ['refund_policy' => 'moderate']);
        $this->travelTo(CarbonImmutable::now('Asia/Riyadh')->addDay()->setTime(9, 0));
        $order = $this->paidLines([
            $this->line($venue, 1, $this->slot(1)), // tomorrow: 50%
            $this->line($venue, 2, $this->slot(5)), // in 5 days: 100%
        ]);
        [$soon, $later] = $order->bookings->all();

        $this->assertEquals(200.0, $this->refunds()->policyQuote($order, [['booking_id' => $later->id]])['amount']);
        $this->assertEquals(50.0, $this->refunds()->policyQuote($order, [['booking_id' => $soon->id]])['amount']);
        // The whole order is judged by its first booking.
        $whole = $this->refunds()->policyQuote($order);
        $this->assertSame(50, $whole['percent']);
        $this->assertEquals(150.0, $whole['amount']);
        $this->assertSame(35, $whole['hours_before_start']);
    }

    public function test_partial_policy_refund_cannot_be_claimed_twice(): void
    {
        // Flexible policy: 50% inside 24 hours.
        $this->travelTo(CarbonImmutable::now('Asia/Riyadh')->addDay()->setTime(9, 0));
        $order = $this->paidOrder($this->venue($this->provider()), 2, null, $this->slot(0));

        $refund = $this->refunds()->request($order->fresh(['venue', 'bookings']), $order->user, null, null);
        $this->assertEquals(100.0, $refund->amount);
        $this->refunds()->approve($refund, $this->admin());

        $order->refresh();
        $this->assertSame(OrderStatus::PartiallyRefunded, $order->status);
        $this->assertSame(BookingStatus::Cancelled, $order->bookings()->first()->status); // the table is given up anyway
        $this->assertSame(0, BookingSlot::count());
        $this->assertBusiness('nothing_left', fn () => $this->refunds()->request($order->fresh(['venue', 'bookings']), $order->user, null, null));
    }

    public function test_amount_only_refund_keeps_the_booking(): void
    {
        $order = $this->paidOrder($this->venue($this->provider()), 2);

        $refund = $this->refunds()->createByAdmin($order, 50, null, 'table was not ready on time', $this->admin());

        $this->assertSame('partial', $refund->type);
        $this->assertSame(OrderStatus::PartiallyRefunded, $order->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $order->bookings()->first()->status);
        $this->assertSame(2, BookingSlot::count());
        $this->assertEquals(5.0, $refund->commission_reversed);
    }

    public function test_refund_items_must_be_active_bookings_of_the_order(): void
    {
        $venue = $this->venue($this->provider());
        $order = $this->paidTables($venue, 2);
        $foreign = $this->paidOrder($venue, 1, null, $this->slot(4))->bookings->first();
        [$first, $second] = $order->bookings->all();
        $request = fn (array $items) => $this->refunds()->request($order->fresh(['venue', 'bookings']), $order->user, $items, null);

        $this->assertBusiness('invalid_items', fn () => $request([['booking_id' => 999999]]));
        $this->assertBusiness('invalid_items', fn () => $request([['booking_id' => $foreign->id]]));
        $this->assertBusiness('invalid_items', fn () => $request([['booking_id' => $first->id], ['id' => 1]]));

        // The same booking listed twice is refunded once.
        $refund = $request([['booking_id' => $first->id], ['booking_id' => $first->id]]);
        $this->assertEquals(100.0, $refund->amount);
        $this->refunds()->approve($refund, $this->admin());

        // A booking that was already refunded cannot be claimed again.
        $this->assertBusiness('invalid_items', fn () => $request([['booking_id' => $first->id]]));
        $this->assertEquals(100.0, $request([['booking_id' => $second->id]])->amount);
    }

    public function test_gateway_failure_marks_the_refund_failed_and_releases_the_amount(): void
    {
        $order = $this->paidOrder($this->venue($this->provider()), 2);
        $order->user->delete(); // no account, so no wallet: the refund goes back through the gateway
        $admin = $this->admin();

        $this->failingRefunds();
        $this->assertBusiness('gateway_failed', fn () => $this->refunds()->createByAdmin($order, 200, null, 'x', $admin));
        $this->assertSame(RefundStatus::Failed, Refund::where('order_id', $order->id)->value('status'));
        $this->assertEquals(0.0, $order->fresh()->refunded_amount);
        $this->assertSame(0, LedgerEntry::whereNotNull('refund_id')->count());
        $this->assertSame(2, BookingSlot::count()); // the customer keeps the table

        $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
        $this->refunds()->createByAdmin($order->fresh(), 200, null, 'retry', $admin);
        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
        $this->assertSame(0, BookingSlot::count());
    }

    public function test_offline_order_refund_only_reverses_commission(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $order = app(OrderService::class)->createOffline($provider, [$this->line($venue, 2)], ['name' => 'Walk-in'], 'cash', $provider->owner);

        $this->refunds()->createByAdmin($order, 200, null, 'returned', $this->admin());

        $this->assertSame(0, LedgerEntry::where('order_id', $order->id)->where('type', 'refund')->count());
        $this->assertEquals(0.0, $this->ledgerSum($order, 'provider'));
        $this->assertEquals(0.0, $this->ledgerSum($order, 'platform'));
        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
        $this->assertSame(0, BookingSlot::count());
    }
}
