<?php

namespace Tests\Feature\Services;

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Models\BookingSlot;
use App\Models\Order;
use App\Services\AvailabilityService;
use App\Services\CheckInService;
use App\Services\OrderService;
use App\Services\RefundService;
use App\Services\VenueService;
use Carbon\CarbonImmutable;

/** Time-slot booking: availability, exclusivity per resource, holds, check-in and refunds. */
class BookingCoreTest extends ServiceTestCase
{
    public function test_availability_lists_every_slot_per_resource(): void
    {
        $venue = $this->venue($this->provider());
        $activity = $this->activity($venue);

        $day = app(AvailabilityService::class)->forDate($activity, $this->slot()->setTimezone('Asia/Riyadh')->format('Y-m-d'));

        $this->assertTrue($day['is_open']);
        $this->assertSame('10:00', $day['opens_at']);
        $this->assertCount(3, $day['resources']);
        $this->assertCount(14, $day['resources'][0]['slots']); // 10:00 -> 00:00, hourly
        $this->assertSame('available', $day['resources'][0]['slots'][0]['status']);
        $this->assertEquals(100.0, $day['slot_price']);
    }

    public function test_booking_holds_the_slots_of_one_resource_only(): void
    {
        $venue = $this->venue($this->provider());
        $table = $this->activity($venue)->resources->first();

        $order = app(OrderService::class)->createOnline($this->customer(), [$this->line($venue, 2, null, $table->id)]);

        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        $this->assertEquals(200.0, $order->total);
        $this->assertSame(2, BookingSlot::where('resource_id', $table->id)->count());
        $this->assertSame(2, BookingSlot::count());

        $day = app(AvailabilityService::class)->forDate($this->activity($venue), $this->slot()->setTimezone('Asia/Riyadh')->format('Y-m-d'));
        $statuses = fn (int $r) => array_column($day['resources'][$r]['slots'], 'status');
        $this->assertSame(['booked', 'booked', 'available'], array_slice($statuses(0), 10, 3)); // 20:00, 21:00, 22:00
        $this->assertSame('available', $statuses(1)[10]);
    }

    public function test_same_table_and_time_cannot_be_booked_twice(): void
    {
        $venue = $this->venue($this->provider());
        $table = $this->activity($venue)->resources->first();
        $orders = app(OrderService::class);

        $orders->createOnline($this->customer(), [$this->line($venue, 2, null, $table->id)]);

        // Overlapping by one hour on the same table.
        $this->assertBusiness('slot_taken', fn () => $orders->createOnline(
            $this->customer('+966502222222'),
            [$this->line($venue, 2, $this->slot(3, '21:00'), $table->id)],
        ));
        $this->assertSame(1, Order::count()); // the failed attempt left nothing behind
    }

    public function test_without_a_chosen_table_the_next_free_one_is_assigned(): void
    {
        $venue = $this->venue($this->provider(), [], [], 2);
        $orders = app(OrderService::class);

        $first = $orders->createOnline($this->customer(), [$this->line($venue)]);
        $second = $orders->createOnline($this->customer('+966502222222'), [$this->line($venue)]);

        $this->assertNotSame($first->bookings->first()->resource_id, $second->bookings->first()->resource_id);
        $this->assertBusiness('slot_taken', fn () => $orders->createOnline($this->customer('+966503333333'), [$this->line($venue)]));
    }

    public function test_slots_must_fit_working_hours_and_the_grid(): void
    {
        $venue = $this->venue($this->provider());
        $orders = app(OrderService::class);
        $customer = $this->customer();

        $this->assertBusiness('outside_hours', fn () => $orders->createOnline($customer, [$this->line($venue, 1, $this->slot(3, '08:00'))]));
        $this->assertBusiness('outside_hours', fn () => $orders->createOnline($customer, [$this->line($venue, 1, $this->slot(3, '20:30'))]));
        $this->assertBusiness('outside_hours', fn () => $orders->createOnline($customer, [$this->line($venue, 2, $this->slot(3, '23:00'))]));
        $this->assertBusiness('slots_range', fn () => $orders->createOnline($customer, [$this->line($venue, 5)]));
        $this->assertBusiness('slot_in_past', fn () => $orders->createOnline($customer, [$this->line($venue, 1, $this->slot(-1))]));
        $this->assertBusiness('too_far_ahead', fn () => $orders->createOnline($customer, [$this->line($venue, 1, $this->slot(40))]));
    }

    public function test_venue_open_past_midnight_accepts_after_midnight_slots(): void
    {
        $venue = $this->venue($this->provider());
        app(VenueService::class)->syncHours($venue, array_map(
            fn (int $d) => ['weekday' => $d, 'opens_at' => '16:00', 'closes_at' => '02:00'], range(0, 6)));

        $order = app(OrderService::class)->createOnline($this->customer(), [$this->line($venue->fresh(), 1, $this->slot(3, '01:00'))]);

        $this->assertSame(1, $order->bookings->count());
    }

    public function test_expired_hold_frees_the_slots(): void
    {
        $venue = $this->venue($this->provider());
        [$order] = $this->checkout($this->customer(), $venue);

        $this->travel(20)->minutes();
        $this->assertSame(1, app(OrderService::class)->expireStale());

        $this->assertSame(OrderStatus::Expired, $order->fresh()->status);
        $this->assertSame(BookingStatus::Expired, $order->bookings()->first()->status);
        $this->assertSame(0, BookingSlot::count());
    }

    public function test_payment_confirms_booking_and_check_in_works_once(): void
    {
        $provider = $this->provider();
        $order = $this->paidOrder($this->venue($provider));
        $booking = $order->bookings->first();

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertEquals(20.0, $order->commission_amount); // 10% of 200

        $checked = app(CheckInService::class)->checkIn($provider, strtolower($booking->code), null, $provider->owner);
        $this->assertSame(BookingStatus::CheckedIn, $checked->status);

        $this->assertBusiness('booking_used', fn () => app(CheckInService::class)->checkIn($provider, $booking->code, null, $provider->owner));
    }

    public function test_full_refund_cancels_the_booking_and_frees_the_slots(): void
    {
        $order = $this->paidOrder($this->venue($this->provider()));

        app(RefundService::class)->createByAdmin($order, 200, null, 'test', $this->admin());

        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
        $this->assertSame(BookingStatus::Cancelled, $order->bookings()->first()->status);
        $this->assertSame(0, BookingSlot::count());
    }

    public function test_finished_bookings_and_orders_are_completed(): void
    {
        $order = $this->paidOrder($this->venue($this->provider()), 1, null, $this->slot(1));

        $this->travelTo(CarbonImmutable::now()->addDays(2));
        $this->assertSame(1, app(OrderService::class)->completeFinished());

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertSame(BookingStatus::Completed, $order->bookings()->first()->status);
    }
}
