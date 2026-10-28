<?php

namespace Tests\Feature\Api;

use App\Models\BookingSlot;
use App\Models\Order;
use App\Services\OrderService;

/**
 * Double taps and repeated requests must not create duplicate orders or confusing errors.
 * (True parallel races are covered by the customer row lock in BookingController, the unique
 * slot key and the row locks in OrderService / CheckInService; these tests pin the observable behaviour.)
 */
class DoubleSubmitTest extends ApiTestCase
{
    public function test_repeated_booking_returns_the_same_order(): void
    {
        $venue = $this->venue($this->provider());
        $customer = $this->customer();
        $payload = $this->line($venue, 2); // no table chosen: a second hold would silently take another table

        $first = $this->actingAs($customer)->postJson('/api/v1/bookings', $payload)->assertCreated();
        $second = $this->actingAs($customer)->postJson('/api/v1/bookings', $payload)->assertCreated();

        $this->assertSame($first->json('data.order.id'), $second->json('data.order.id'));
        $this->assertSame($first->json('data.payment.reference'), $second->json('data.payment.reference'));
        $this->assertSame($first->json('data.order.bookings.0.id'), $second->json('data.order.bookings.0.id'));
        $this->assertSame(1, Order::where('user_id', $customer->id)->count());
        $this->assertSame(2, BookingSlot::count());

        // The same request written as lines[] is still the same booking.
        $third = $this->actingAs($customer)->postJson('/api/v1/bookings', ['lines' => [$payload]])->assertCreated();
        $this->assertSame($first->json('data.order.id'), $third->json('data.order.id'));

        // A different time or length is a new order.
        $this->actingAs($customer)->postJson('/api/v1/bookings', $this->line($venue, 1))->assertCreated();
        $this->actingAs($customer)->postJson('/api/v1/bookings', $this->line($venue, 2, $this->slot(4)))->assertCreated();
        $this->assertSame(3, Order::where('user_id', $customer->id)->count());
    }

    public function test_repeated_booking_of_a_chosen_table_is_not_reported_as_taken(): void
    {
        $venue = $this->venue($this->provider());
        $tables = $this->activity($venue)->resources;
        $customer = $this->customer();
        $payload = $this->line($venue, 1, null, $tables[0]->id);

        $first = $this->actingAs($customer)->postJson('/api/v1/bookings', $payload)->assertCreated();
        $second = $this->actingAs($customer)->postJson('/api/v1/bookings', $payload)->assertCreated();
        $this->assertSame($first->json('data.order.id'), $second->json('data.order.id'));

        // Another table at the same time is a deliberate second booking.
        $other = $this->actingAs($customer)->postJson('/api/v1/bookings', $this->line($venue, 1, null, $tables[1]->id))->assertCreated();
        $this->assertNotSame($first->json('data.order.id'), $other->json('data.order.id'));

        // Someone else asking for the same table is told it is taken.
        $this->actingAs($this->customer('+966502222222'))->postJson('/api/v1/bookings', $payload)
            ->assertStatus(409)->assertJsonPath('code', 'slot_taken');
    }

    public function test_the_same_booking_after_the_window_is_a_new_one(): void
    {
        $venue = $this->venue($this->provider());
        $customer = $this->customer();
        $payload = ['activity_id' => $this->activity($venue)->id, 'starts_at' => $this->slot()->toIso8601String(), 'slots' => 1];

        $first = $this->actingAs($customer)->postJson('/api/v1/bookings', $payload)->assertCreated();
        $this->travel(30)->seconds();
        $second = $this->actingAs($customer)->postJson('/api/v1/bookings', $payload)->assertCreated();

        // Deliberately booking a second table for the same hour.
        $this->assertNotSame($first->json('data.order.id'), $second->json('data.order.id'));
        $this->assertNotSame($first->json('data.order.bookings.0.resource.id'), $second->json('data.order.bookings.0.resource.id'));
    }

    public function test_an_expired_order_is_not_handed_back_as_a_duplicate(): void
    {
        $venue = $this->venue($this->provider());
        $customer = $this->customer();
        $payload = $this->line($venue, 1);

        $first = $this->actingAs($customer)->postJson('/api/v1/bookings', $payload)->assertCreated()->json('data.order.id');
        app(OrderService::class)->expire(Order::findOrFail($first));

        $second = $this->actingAs($customer)->postJson('/api/v1/bookings', $payload)->assertCreated();
        $this->assertNotSame($first, $second->json('data.order.id'));
        $this->assertSame('pending_payment', $second->json('data.order.status.value'));
    }

    public function test_validation_and_rate_limit_messages_are_translated(): void
    {
        $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/auth/otp/request', ['identifier' => '123'])
            ->assertStatus(422)->assertJsonPath('message', __('validation.custom.identifier.regex', [], 'ar'));

        for ($i = 0; $i < 8; $i++) {
            $response = $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/auth/otp/request', ['identifier' => '05'.random_int(10000000, 99999999)]);
        }
        $response->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
        $this->assertStringNotContainsString('Too Many Attempts', $response->json('message'));
    }
}
