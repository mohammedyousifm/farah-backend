<?php

namespace Tests\Feature\Api;

use App\Models\BehaviorEvent;
use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\WebhookEvent;
use App\Services\Integrations\Drivers\FakePaymentGateway;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/** Pick slots → pending order with held slots → signed webhook → confirmed booking, ledger → refund request. */
class BookingFlowTest extends ApiTestCase
{
    private function webhook(string $reference, string $eventId, string $type = 'payment.paid'): TestResponse
    {
        $body = json_encode(['id' => $eventId, 'type' => $type, 'data' => ['reference' => $reference, 'id' => 'pay_1', 'method' => 'mada']]);

        return $this->call('POST', '/api/v1/payments/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => FakePaymentGateway::sign($body),
        ], $body);
    }

    public function test_full_booking_flow(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider, ['address' => 'حي العليا', 'phone' => '0112223344']);
        $activity = $this->activity($venue);
        $table = $activity->resources->first();
        $customer = $this->customer();
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/bookings')->assertOk()->assertJsonCount(0, 'data');

        // Book table 1 for two hours → pending order + payment with checkout URL; the slots are held.
        $booked = $this->postJson('/api/v1/bookings', [
            'activity_id' => $activity->id,
            'resource_id' => $table->id,
            'starts_at' => $this->slot()->toIso8601String(),
            'slots' => 2,
        ], ['Accept-Language' => 'en'])->assertCreated()
            ->assertJsonStructure(['data' => [
                'order' => ['id', 'number', 'channel', 'status' => ['value', 'label', 'color'], 'subtotal', 'vat_amount', 'total', 'refunded_amount',
                    'currency', 'expires_at', 'confirmed_at', 'created_at', 'venue' => ['id', 'name', 'cover_url'],
                    'bookings' => [['id', 'code', 'status' => ['value', 'label', 'color'], 'name', 'starts_at', 'ends_at', 'slots', 'slot_minutes',
                        'unit_price', 'total', 'checked_in_at', 'activity' => ['id', 'name'], 'resource' => ['id', 'name'],
                        'venue' => ['id', 'name', 'cover_url', 'address', 'lat', 'lng', 'phone', 'city' => ['name']],
                        'order' => ['id', 'number', 'status']]]],
                'payment' => ['reference', 'checkout_url', 'amount', 'status' => ['value']],
            ]])
            ->assertJsonPath('data.order.status.value', 'pending_payment')
            ->assertJsonPath('data.order.channel', 'online')
            ->assertJsonPath('data.order.total', 200)
            ->assertJsonPath('data.order.vat_amount', 26.09)
            ->assertJsonPath('data.order.venue.id', $venue->id)
            ->assertJsonCount(1, 'data.order.bookings')
            ->assertJsonPath('data.order.bookings.0.status.value', 'pending')
            ->assertJsonPath('data.order.bookings.0.name', 'Billiards - Table 1')
            ->assertJsonPath('data.order.bookings.0.slots', 2)
            ->assertJsonPath('data.order.bookings.0.unit_price', 100)
            ->assertJsonPath('data.order.bookings.0.total', 200)
            ->assertJsonPath('data.order.bookings.0.resource.id', $table->id)
            ->assertJsonPath('data.order.bookings.0.venue.phone', '0112223344')
            ->assertJsonPath('data.payment.status.value', 'initiated')
            ->assertJsonPath('data.payment.amount', 200);

        $reference = $booked->json('data.payment.reference');
        $orderId = $booked->json('data.order.id');
        $bookingId = $booked->json('data.order.bookings.0.id');
        $this->assertStringContainsString("/payments/fake/{$reference}", $booked->json('data.payment.checkout_url'));
        $this->assertSame($orderId, $booked->json('data.order.bookings.0.order.id'));
        $this->assertTrue($this->slot()->equalTo(CarbonImmutable::parse($booked->json('data.order.bookings.0.starts_at'))));
        $this->assertTrue($this->slot()->addHours(2)->equalTo(CarbonImmutable::parse($booked->json('data.order.bookings.0.ends_at'))));
        $this->assertSame(2, BookingSlot::where('resource_id', $table->id)->count());
        $this->assertNotNull($booked->json('data.order.expires_at'));

        // Signed webhook → paid.
        $this->webhook($reference, 'evt_1')->assertOk()->assertJsonPath('duplicate', false);

        $order = Order::findOrFail($orderId);
        $this->assertSame('confirmed', $order->status->value);
        $this->assertSame('confirmed', Booking::findOrFail($bookingId)->status->value);
        $this->assertEquals(20.0, (float) $order->commission_amount); // 10% global rule on 200

        $ledger = LedgerEntry::where('order_id', $orderId)->get();
        $this->assertEquals(200.0, (float) $ledger->where('account', 'provider')->where('type.value', 'sale')->sum('amount'));
        $this->assertEquals(-20.0, (float) $ledger->where('account', 'provider')->where('type.value', 'commission')->sum('amount'));
        $this->assertEquals(20.0, (float) $ledger->where('account', 'platform')->where('type.value', 'commission')->sum('amount'));

        // Both sides are told, in the new wording.
        $this->assertSame(1, $customer->notifications()->where('data->key', 'order_confirmed')->count());
        $this->assertStringContainsString($venue->name_ar, $customer->notifications()->first()->data['body_ar']);
        $this->assertSame(1, $provider->owner->notifications()->where('data->key', 'new_order')->count());

        // Duplicate webhook is acknowledged but not re-applied.
        $this->webhook($reference, 'evt_1')->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, WebhookEvent::where('event_id', 'evt_1')->count());
        $this->assertSame(3, LedgerEntry::where('order_id', $orderId)->count());
        $this->assertSame(2, BookingSlot::count());

        // Bad signature is rejected.
        $this->call('POST', '/api/v1/payments/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => 'nope'],
            json_encode(['id' => 'evt_2', 'type' => 'payment.paid', 'data' => ['reference' => $reference]]))
            ->assertStatus(401)->assertJsonPath('code', 'invalid_signature');

        // Customer sees the payment, the order and the booking with its code.
        $this->getJson("/api/v1/payments/{$reference}")->assertOk()->assertJsonPath('data.status.value', 'paid');
        $this->getJson("/api/v1/orders/{$orderId}")->assertOk()
            ->assertJsonPath('data.status.value', 'confirmed')
            ->assertJsonPath('data.has_review', false)
            ->assertJsonPath('data.payment.reference', $reference)
            ->assertJsonCount(1, 'data.bookings')
            ->assertJsonPath('data.bookings.0.status.value', 'confirmed');
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.bookings.0.id', $bookingId);
        $this->getJson('/api/v1/orders?scope=past')->assertOk()->assertJsonCount(0, 'data');

        $code = Booking::findOrFail($bookingId)->code;
        $this->getJson('/api/v1/bookings')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bookingId)
            ->assertJsonPath('data.0.code', $code)
            ->assertJsonPath('data.0.status.value', 'confirmed')
            ->assertJsonPath('data.0.venue.id', $venue->id)
            ->assertJsonPath('data.0.order.number', $order->number)
            ->assertJsonPath('data.0.order.status.value', 'confirmed');
        $this->getJson("/api/v1/bookings/{$bookingId}")->assertOk()->assertJsonPath('data.code', $code)->assertJsonPath('data.activity.id', $activity->id);
        $this->getJson('/api/v1/bookings?scope=past')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/bookings?scope=all')->assertOk()->assertJsonCount(1, 'data');

        // Nobody cancels an order from the app; a refund can be requested.
        $this->postJson("/api/v1/orders/{$orderId}/cancel")->assertNotFound();
        $this->getJson("/api/v1/orders/{$orderId}/refund-quote")->assertOk()
            ->assertJsonPath('data.policy', 'flexible')
            ->assertJsonPath('data.percent', 100)
            ->assertJsonPath('data.amount', 200)
            ->assertJsonPath('data.eligible', true);
        $this->postJson("/api/v1/orders/{$orderId}/refund", ['reason' => 'Cannot make it', 'items' => [['booking_id' => 999999]]])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_items');
        $this->postJson("/api/v1/orders/{$orderId}/refund", ['reason' => 'Cannot make it', 'items' => [['booking_id' => $bookingId]]])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'requested')
            ->assertJsonPath('data.type', 'full')
            ->assertJsonPath('data.amount', 200)
            ->assertJsonPath('data.items', [['booking_id' => $bookingId]]);
        $this->postJson("/api/v1/orders/{$orderId}/refund")->assertStatus(422)->assertJsonPath('code', 'refund_pending');

        // Not before the visit...
        $this->postJson('/api/v1/reviews', ['order_id' => $orderId, 'rating' => 5])->assertStatus(403)->assertJsonPath('code', 'review_too_early');

        // ...but once the booked time has started; the venue rating follows.
        $this->travelTo($this->slot()->addMinutes(10));
        $this->postJson('/api/v1/reviews', ['order_id' => $orderId, 'rating' => 5, 'comment' => 'رائع'])->assertCreated();
        $this->postJson('/api/v1/reviews', ['order_id' => $orderId, 'rating' => 4])->assertStatus(422)->assertJsonPath('code', 'already_reviewed');
        $this->getJson("/api/v1/orders/{$orderId}")->assertJsonPath('data.has_review', true);
        $this->getJson("/api/v1/venues/{$venue->id}/reviews")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.rating_avg', 5);

        // Once the time has passed the booking moves to "past".
        $this->travelTo($this->slot()->addHours(5));
        app(OrderService::class)->completeFinished();
        $this->getJson('/api/v1/bookings')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/bookings?scope=past')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status.value', 'completed');
        $this->getJson('/api/v1/orders?scope=past')->assertOk()->assertJsonCount(1, 'data');

        // Another customer can't see this order, booking or payment.
        Sanctum::actingAs($this->customer('+966503333333'));
        $this->getJson("/api/v1/orders/{$orderId}")->assertNotFound();
        $this->getJson("/api/v1/bookings/{$bookingId}")->assertNotFound();
        $this->getJson('/api/v1/bookings?scope=all')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/payments/{$reference}")->assertNotFound();
        $this->postJson("/api/v1/orders/{$orderId}/refund")->assertNotFound();
    }

    public function test_slot_conflict_returns_409_slot_taken(): void
    {
        $venue = $this->venue($this->provider());
        $table = $this->activity($venue)->resources->first();

        Sanctum::actingAs($this->customer());
        $this->postJson('/api/v1/bookings', $this->line($venue, 2, null, $table->id))->assertCreated();

        // Another customer, same table, overlapping by one hour.
        Sanctum::actingAs($this->customer('+966502222222'));
        $this->postJson('/api/v1/bookings', $this->line($venue, 2, $this->slot(3, '21:00'), $table->id), ['Accept-Language' => 'en'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'slot_taken')
            ->assertJsonPath('message', __('api.bookings.slot_taken', [], 'en'));
        $this->assertSame(1, Order::count()); // the failed attempt left nothing behind
        $this->assertSame(2, BookingSlot::count());

        // Right after the first booking is fine, and so is another table at the same time.
        $this->postJson('/api/v1/bookings', $this->line($venue, 1, $this->slot(3, '22:00'), $table->id))->assertCreated();
        $this->postJson('/api/v1/bookings', $this->line($venue, 2, null, $this->activity($venue)->resources->last()->id))->assertCreated();
    }

    public function test_without_a_chosen_table_the_next_free_one_is_assigned_until_full(): void
    {
        $venue = $this->venue($this->provider(), [], [], 2);
        $taken = [];

        foreach (['+966501000001', '+966501000002'] as $phone) {
            Sanctum::actingAs($this->customer($phone));
            $taken[] = $this->postJson('/api/v1/bookings', $this->line($venue))->assertCreated()->json('data.order.bookings.0.resource.id');
        }
        $this->assertCount(2, array_unique($taken));

        Sanctum::actingAs($this->customer('+966501000003'));
        $this->postJson('/api/v1/bookings', $this->line($venue))->assertStatus(409)->assertJsonPath('code', 'slot_taken');
    }

    public function test_booking_rules_are_reported_with_their_codes(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $other = $this->venue($this->provider(null, 'Other'));
        $activity = $this->activity($venue);
        Sanctum::actingAs($this->customer());

        $book = fn (array $line) => $this->postJson('/api/v1/bookings', $line);

        $book($this->line($venue, 1, $this->slot(3, '08:00')))->assertStatus(422)->assertJsonPath('code', 'outside_hours');
        $book($this->line($venue, 1, $this->slot(3, '20:30')))->assertStatus(422)->assertJsonPath('code', 'outside_hours'); // off the grid
        $book($this->line($venue, 2, $this->slot(3, '23:00')))->assertStatus(422)->assertJsonPath('code', 'outside_hours'); // runs past closing
        $book($this->line($venue, 5))->assertStatus(422)->assertJsonPath('code', 'slots_range');
        $book($this->line($venue, 1, $this->slot(-1)))->assertStatus(422)->assertJsonPath('code', 'slot_in_past');
        $book($this->line($venue, 1, $this->slot(40)))->assertStatus(422)->assertJsonPath('code', 'too_far_ahead');
        $book($this->line($venue, 1, null, $this->activity($other)->resources->first()->id))->assertStatus(422)->assertJsonPath('code', 'resource_unavailable');
        $book(['activity_id' => 999999, 'starts_at' => $this->slot()->toIso8601String(), 'slots' => 1])->assertStatus(422)->assertJsonPath('code', 'activity_unavailable');
        $book(['lines' => [$this->line($venue, 1), $this->line($other, 1)]])->assertStatus(422)->assertJsonPath('code', 'one_venue_per_order');
        $book(['lines' => array_map(fn (int $h) => $this->line($venue, 1, $this->slot(3, "1{$h}:00")), range(0, 4))])
            ->assertStatus(422)->assertJsonPath('code', 'max_per_order');

        // Request validation.
        $book([])->assertStatus(422)->assertJsonValidationErrors(['activity_id', 'starts_at', 'slots']);
        $book(['activity_id' => $activity->id, 'starts_at' => 'soon', 'slots' => 0])->assertStatus(422)->assertJsonValidationErrors(['starts_at', 'slots']);
        $book(['lines' => [['activity_id' => $activity->id]]])->assertStatus(422)->assertJsonValidationErrors(['lines.0.starts_at', 'lines.0.slots']);

        // A venue that stopped taking bookings.
        $venue->update(['status' => 'suspended']);
        $book($this->line($venue))->assertStatus(422)->assertJsonPath('code', 'not_bookable');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, BookingSlot::count());
    }

    public function test_several_bookings_in_one_order_and_partial_refund(): void
    {
        $venue = $this->venue($this->provider());
        Sanctum::actingAs($this->customer());

        // Two tables at the same time, plus one more hour later: one order, one payment.
        $booked = $this->postJson('/api/v1/bookings', ['lines' => [
            $this->line($venue, 1),
            $this->line($venue, 1),
            $this->line($venue, 2, $this->slot(3, '22:00')),
        ]])->assertCreated()
            ->assertJsonCount(3, 'data.order.bookings')
            ->assertJsonPath('data.order.total', 400)
            ->assertJsonPath('data.payment.amount', 400);

        $bookings = collect($booked->json('data.order.bookings'));
        $this->assertCount(2, $bookings->where('slots', 1)->pluck('resource.id')->unique());
        $orderId = $booked->json('data.order.id');

        $this->webhook($booked->json('data.payment.reference'), 'evt_multi')->assertOk();

        // Give up only the late booking.
        $late = $bookings->firstWhere('slots', 2)['id'];
        $this->postJson("/api/v1/orders/{$orderId}/refund", ['items' => [['booking_id' => $late]]])
            ->assertCreated()
            ->assertJsonPath('data.type', 'partial')
            ->assertJsonPath('data.amount', 200);
    }

    public function test_a_start_time_without_offset_is_riyadh_wall_clock(): void
    {
        $venue = $this->venue($this->provider());
        Sanctum::actingAs($this->customer());

        $local = $this->slot()->setTimezone('Asia/Riyadh')->format('Y-m-d H:i:s'); // "…… 20:00:00"

        $start = $this->postJson('/api/v1/bookings', ['activity_id' => $this->activity($venue)->id, 'starts_at' => $local, 'slots' => 1])
            ->assertCreated()->json('data.order.bookings.0.starts_at');

        $this->assertTrue($this->slot()->equalTo(CarbonImmutable::parse($start)));
    }

    public function test_unpaid_order_can_be_paid_again_and_expires(): void
    {
        $venue = $this->venue($this->provider());
        Sanctum::actingAs($this->customer());

        // Pay again: a fresh checkout session for the same held order.
        $second = $this->postJson('/api/v1/bookings', $this->line($venue))->assertCreated();
        $orderId = $second->json('data.order.id');
        $this->travel(20)->seconds();
        $retry = $this->postJson("/api/v1/orders/{$orderId}/pay")->assertCreated()->assertJsonPath('data.payment.status.value', 'initiated');
        $this->assertNotSame($second->json('data.payment.reference'), $retry->json('data.payment.reference'));
        $this->assertSame(2, BookingSlot::count());

        // Not paid in time: the hold is dropped and the order can no longer be paid.
        $this->travel(20)->minutes();
        $this->assertSame(1, app(OrderService::class)->expireStale());
        $this->assertSame(0, BookingSlot::count());
        $this->getJson("/api/v1/orders/{$orderId}")->assertJsonPath('data.status.value', 'expired')->assertJsonPath('data.bookings.0.status.value', 'expired');
        $this->postJson("/api/v1/orders/{$orderId}/pay")->assertStatus(422)->assertJsonPath('code', 'order_not_payable');
    }

    public function test_failed_payment_and_fake_gateway_page(): void
    {
        $venue = $this->venue($this->provider());
        Sanctum::actingAs($this->customer());

        $reference = $this->postJson('/api/v1/bookings', $this->line($venue))->assertCreated()->json('data.payment.reference');

        $this->get("/payments/fake/{$reference}")->assertOk()->assertSee('ادفع الآن')->assertSee($venue->name_ar);
        $this->post("/payments/fake/{$reference}", ['action' => 'fail'])->assertOk()->assertSee('لم تكتمل');

        $this->getJson("/api/v1/payments/{$reference}")->assertJsonPath('data.status.value', 'failed');
        $this->assertSame('pending_payment', Order::first()->status->value);
        $this->assertSame(2, BookingSlot::count()); // still held until the order expires
    }

    public function test_fake_gateway_pay_button_confirms_the_booking(): void
    {
        $venue = $this->venue($this->provider());
        Sanctum::actingAs($this->customer());

        $reference = $this->postJson('/api/v1/bookings', $this->line($venue, 3))->json('data.payment.reference');

        $this->post("/payments/fake/{$reference}", ['action' => 'pay'])->assertOk()->assertSee('تم الدفع بنجاح')->assertSee('تم تأكيد حجزك');
        $this->assertSame('confirmed', Order::first()->status->value);
        $this->assertSame('confirmed', Booking::first()->status->value);
        $this->assertSame(3, BookingSlot::count());
    }

    public function test_booking_is_tracked_only_with_consent(): void
    {
        $venue = $this->venue($this->provider());

        Sanctum::actingAs($this->customer());
        $this->postJson('/api/v1/bookings', $this->line($venue))->assertCreated();
        $this->assertSame(0, BehaviorEvent::count());

        Sanctum::actingAs($this->customer('+966504444444', ['tracking_consent' => true]));
        $this->postJson('/api/v1/bookings', $this->line($venue))->assertCreated();
        $this->assertSame(1, BehaviorEvent::where('name', 'start_booking')->where('subject_type', 'venue')->where('subject_id', $venue->id)->count());

        $this->postJson('/api/v1/track', ['events' => [['name' => 'view_venue', 'subject_type' => 'venue', 'subject_id' => $venue->id]]])
            ->assertStatus(202)->assertJsonPath('data.stored', 1);
        $this->postJson('/api/v1/track', ['events' => [['name' => 'view_event', 'subject_type' => 'event', 'subject_id' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors(['events.0.name', 'events.0.subject_type']);
    }
}
