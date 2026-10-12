<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Notifications\SystemNotification;
use App\Services\ProviderService;
use App\Services\VenueService;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/** A double tap or a second click never performs the action twice. */
class DuplicateRequestTest extends ApiTestCase
{
    public function test_the_same_booking_sent_twice_creates_one_order(): void
    {
        config(['farah.api.dedupe_seconds' => 5]);

        $venue = $this->venue($this->provider());
        $activity = $this->activity($venue);
        Sanctum::actingAs($this->customer());

        // No table chosen: without the protection the second request would simply take the next free table.
        $payload = ['activity_id' => $activity->id, 'starts_at' => $this->slot()->toIso8601String(), 'slots' => 1];

        $first = $this->postJson('/api/v1/bookings', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/bookings', $payload)->assertCreated()->assertHeader('X-Duplicate-Request', 'replayed');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::count());
    }

    public function test_a_different_booking_right_after_is_not_blocked(): void
    {
        config(['farah.api.dedupe_seconds' => 5]);

        $venue = $this->venue($this->provider());
        $activity = $this->activity($venue);
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/bookings', ['activity_id' => $activity->id, 'starts_at' => $this->slot()->toIso8601String(), 'slots' => 1])->assertCreated();
        $this->postJson('/api/v1/bookings', ['activity_id' => $activity->id, 'starts_at' => $this->slot(4)->toIso8601String(), 'slots' => 1])
            ->assertCreated()->assertHeaderMissing('X-Duplicate-Request');

        $this->assertSame(2, Order::count());
    }

    public function test_a_failed_request_can_be_tried_again(): void
    {
        config(['farah.api.dedupe_seconds' => 5]);
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/bookings', ['activity_id' => 999999])->assertStatus(422);
        $this->postJson('/api/v1/bookings', ['activity_id' => 999999])->assertStatus(422)->assertHeaderMissing('X-Duplicate-Request');
    }

    public function test_approving_twice_notifies_once(): void
    {
        Notification::fake();

        $provider = $this->provider();
        $venue = $this->venue($provider, ['status' => 'pending_review']);
        $admin = $this->customer('+966509999999');

        app(VenueService::class)->approve($venue, $admin);
        app(VenueService::class)->approve($venue, $admin);

        Notification::assertSentToTimes($provider->owner, SystemNotification::class, 1);

        app(ProviderService::class)->suspend($provider, $admin, 'x');
        app(ProviderService::class)->suspend($provider, $admin, 'x');

        Notification::assertSentToTimes($provider->owner, SystemNotification::class, 2);
    }
}
