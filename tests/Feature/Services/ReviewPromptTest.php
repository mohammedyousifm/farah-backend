<?php

namespace Tests\Feature\Services;

use App\Models\Review;
use App\Notifications\SystemNotification;
use App\Services\OrderService;
use Illuminate\Support\Facades\Notification;

/** When the booked time ends, the customer is asked (once) to rate the visit. */
class ReviewPromptTest extends ServiceTestCase
{
    /** How many rating requests this person received (other notices, like "booking confirmed", do not count). */
    private function asked(object $user): int
    {
        return Notification::sent($user, SystemNotification::class, fn (SystemNotification $n) => $n->key === 'review_prompt')->count();
    }

    public function test_customer_is_asked_to_rate_after_the_booking_ends_and_only_once(): void
    {
        Notification::fake();
        $venue = $this->venue($this->provider());
        $customer = $this->customer();
        // One hour, 20:00 to 21:00, tomorrow.
        $order = $this->paidOrder($venue, 1, $customer, $this->slot(1));
        $orders = app(OrderService::class);

        // Before and during the booked hour: nothing.
        $orders->completeFinished();
        $this->assertSame(0, $orders->promptReviews());
        $booking = $order->bookings->first();
        $this->travelTo($booking->ends_at->subMinutes(10));
        $orders->completeFinished();
        $this->assertSame(0, $orders->promptReviews());
        $this->assertSame(0, $this->asked($customer));

        // Five minutes after it ends: one request, carrying what the app needs to open the rating.
        $this->travelTo($booking->ends_at->addMinutes(5));
        $orders->completeFinished();
        $this->assertSame(1, $orders->promptReviews());
        Notification::assertSentTo($customer, SystemNotification::class, fn (SystemNotification $n) => $n->key === 'review_prompt'
            && $n->data['action'] === 'review' && $n->data['order_id'] === $order->id);
        $this->assertNotNull($order->fresh()->review_prompted_at);

        // The next runs stay quiet.
        $this->assertSame(0, $orders->promptReviews());
        $this->travel(10)->minutes();
        $this->assertSame(0, $orders->promptReviews());
        $this->assertSame(1, $this->asked($customer));
    }

    public function test_no_request_when_already_rated_cancelled_or_long_past(): void
    {
        Notification::fake();
        $venue = $this->venue($this->provider());
        $orders = app(OrderService::class);

        $rated = $this->paidOrder($venue, 1, $this->customer('+966502000001'), $this->slot(1, '18:00'));
        $cancelled = $this->paidOrder($venue, 1, $this->customer('+966502000002'), $this->slot(1, '19:00'));
        $old = $this->paidOrder($venue, 1, $this->customer('+966502000003'), $this->slot(1, '20:00'));

        Review::create(['user_id' => $rated->user_id, 'venue_id' => $venue->id, 'provider_id' => $venue->provider_id, 'order_id' => $rated->id, 'rating' => 5, 'status' => 'published']);
        $orders->releaseBookings($cancelled->bookings);

        $this->travelTo($this->slot(1, '21:30'));
        $orders->completeFinished();
        // Only the third order is left to ask about...
        $this->assertSame(1, $orders->promptReviews());
        $this->assertSame(0, $this->asked($rated->user));
        $this->assertSame(0, $this->asked($cancelled->user));

        // ...and a visit that ended days ago is never dug up.
        $late = $this->paidOrder($venue, 1, $this->customer('+966502000004'), $this->slot(3));
        $this->travelTo($this->slot(6));
        $orders->completeFinished();
        $this->assertSame(0, $orders->promptReviews());
        $this->assertSame(0, $this->asked($late->user));
        $this->assertNotNull($old->fresh()->review_prompted_at);
    }
}
