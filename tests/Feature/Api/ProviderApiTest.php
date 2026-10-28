<?php

namespace Tests\Feature\Api;

use App\Enums\UserType;
use App\Enums\VenueStatus;
use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Provider;
use App\Models\Sector;
use App\Models\User;
use App\Models\Venue;
use App\Services\OrderService;
use App\Services\ProviderService;
use App\Services\VenueService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

class ProviderApiTest extends ApiTestCase
{
    public function test_registration_creates_a_pending_provider_owned_by_the_user(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/provider/profile')->assertForbidden()->assertJsonPath('code', 'not_provider_member');

        $details = [
            'name_ar' => 'مؤسسة الفرح',
            'name_en' => 'Farah Co',
            'city_id' => $this->city->id,
            'cr_number' => '1010123456',
            'maps_url' => 'https://maps.app.goo.gl/AbCdEf123456',
            'website' => 'https://farah-co.sa',
            'sector_ids' => [$this->sector->id],
        ];

        // Establishments only, and the place must be given as a Google Maps link.
        $this->postJson('/api/v1/provider/register', ['type' => 'individual', 'national_id' => '1234567890'] + array_diff_key($details, ['cr_number' => 1]))
            ->assertStatus(422)->assertJsonValidationErrors('cr_number');
        $this->postJson('/api/v1/provider/register', array_diff_key($details, ['maps_url' => 1]))
            ->assertStatus(422)->assertJsonValidationErrors('maps_url');
        $this->postJson('/api/v1/provider/register', ['maps_url' => 'https://example.com/maps/our-place'] + $details)
            ->assertStatus(422)->assertJsonValidationErrors('maps_url');

        $this->postJson('/api/v1/provider/register', array_diff_key($details, ['website' => 1]))->assertCreated()
            ->assertJsonPath('data.status.value', 'pending')
            ->assertJsonPath('data.type.value', 'establishment')
            ->assertJsonPath('data.maps_url', 'https://maps.app.goo.gl/AbCdEf123456')
            ->assertJsonPath('data.website', null)
            ->assertJsonPath('data.cr_number', '1010123456');

        $this->getJson('/api/v1/provider/profile')->assertOk()->assertJsonPath('data.name_en', 'Farah Co');
        $this->getJson('/api/v1/provider/sectors')->assertOk()->assertJsonPath('data.0.approval_status', 'pending');

        Storage::fake('public');
        Storage::fake('local');
        $this->post('/api/v1/provider/documents', [
            'type' => 'commercial_register',
            'file' => UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status.value', 'pending');

        // One request: before approval the provider prepares the store and its activities (in the activity
        // types it asked for) and submits it; approving the provider publishes the store.
        $venueId = $this->postJson('/api/v1/provider/venues', ['city_id' => $this->city->id, 'name_ar' => 'صالة جديدة'])->assertCreated()->json('data.id');
        $other = Sector::where('slug', 'bowling')->firstOrFail();
        $this->postJson("/api/v1/provider/venues/{$venueId}/activities", ['sector_id' => $other->id, 'name_ar' => 'بولينج', 'price_per_hour' => 80])
            ->assertForbidden()->assertJsonPath('code', 'sector_not_allowed'); // not an activity type it asked for
        $this->postJson("/api/v1/provider/venues/{$venueId}/activities", ['sector_id' => $this->sector->id, 'name_ar' => 'بلياردو', 'price_per_hour' => 80, 'resources_count' => 2])
            ->assertCreated()->assertJsonPath('data.review_status', 'approved');
        $this->postJson("/api/v1/provider/venues/{$venueId}/submit")->assertOk();
        $this->assertSame(VenueStatus::PendingReview, Venue::find($venueId)->status);
        $this->getJson("/api/v1/venues/{$venueId}")->assertNotFound(); // not visible to customers yet

        $provider = Provider::where('name_en', 'Farah Co')->firstOrFail();
        $provider->documents()->update(['status' => 'approved']);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@farah.sa', 'password' => 'password', 'type' => UserType::Admin, 'status' => 'active']);
        app(ProviderService::class)->approve($provider, $admin);
        $this->assertSame(VenueStatus::Published, Venue::find($venueId)->status);
        $this->getJson("/api/v1/venues/{$venueId}")->assertOk();
    }

    public function test_venue_management_and_submission(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $provider = $this->provider();
        Sanctum::actingAs($provider->owner);

        // Create: a draft with default hours (daily 10:00 -> 00:00).
        $created = $this->postJson('/api/v1/provider/venues', [
            'city_id' => $this->city->id,
            'name_ar' => 'صالة الكرة الثامنة',
            'name_en' => 'Eight Ball Hall',
            'address' => 'حي الملقا',
            'lat' => 24.81,
            'lng' => 46.61,
            'phone' => '0112223344',
            'refund_policy' => 'flexible',
            'advance_booking_days' => 7,
        ], ['Accept-Language' => 'en'])->assertCreated()
            ->assertJsonPath('data.status.value', 'draft')
            ->assertJsonPath('data.name', 'Eight Ball Hall')
            ->assertJsonPath('data.advance_booking_days', 7)
            ->assertJsonPath('data.is_bookable', false)
            ->assertJsonCount(7, 'data.hours')
            ->assertJsonPath('data.hours.3.opens_at', '10:00')
            ->assertJsonCount(0, 'data.activities');
        $venueId = $created->json('data.id');
        $base = "/api/v1/provider/venues/{$venueId}";

        $this->postJson('/api/v1/provider/venues', ['name_en' => 'No Arabic name'])->assertStatus(422)->assertJsonValidationErrors(['name_ar', 'city_id']);

        // One venue per provider: a second one is refused.
        $this->postJson('/api/v1/provider/venues', ['city_id' => $this->city->id, 'name_ar' => 'فرع ثانٍ'])->assertStatus(422)->assertJsonPath('code', 'venue_limit');
        $this->assertSame(1, $provider->venues()->count());

        // Cannot go to review without something to book.
        $this->postJson("{$base}/submit")->assertStatus(422)->assertJsonPath('code', 'no_activities');

        // Working hours: Friday from 16:00 past midnight, Sunday closed.
        $this->putJson("{$base}/hours", ['hours' => [
            ['weekday' => 5, 'opens_at' => '16:00', 'closes_at' => '02:00'],
            ['weekday' => 0, 'is_closed' => true],
        ]])->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('data.5.opens_at', '16:00')
            ->assertJsonPath('data.5.closes_at', '02:00')
            ->assertJsonPath('data.0.is_closed', true)
            ->assertJsonPath('data.0.opens_at', null)
            ->assertJsonPath('data.1.opens_at', '10:00'); // untouched
        $this->getJson("{$base}/hours")->assertOk()->assertJsonPath('data.5.closes_at', '02:00');
        $this->putJson("{$base}/hours", ['hours' => [['weekday' => 7, 'opens_at' => '10am']]])
            ->assertStatus(422)->assertJsonValidationErrors(['hours.0.weekday', 'hours.0.opens_at']);

        // Activity with four tables in one go.
        $activity = $this->postJson("{$base}/activities", [
            'sector_id' => $this->sector->id,
            'name_ar' => 'بلياردو',
            'name_en' => 'Billiards',
            'price_per_hour' => 80,
            'slot_minutes' => 30,
            'min_slots' => 2,
            'max_slots' => 6,
            'capacity' => 4,
            'resources_count' => 4,
        ])->assertCreated()
            ->assertJsonPath('data.price_per_hour', 80)
            ->assertJsonPath('data.slot_minutes', 30)
            ->assertJsonPath('data.slot_price', 40)
            ->assertJsonPath('data.sector.slug', 'billiards')
            ->assertJsonPath('data.resources_count', 4)
            ->assertJsonCount(4, 'data.resources');
        $activityId = $activity->json('data.id');
        $this->assertEquals(80.0, Venue::find($venueId)->min_price);

        // Resources: add, rename, deactivate, delete.
        $resourceId = $this->postJson("{$base}/activities/{$activityId}/resources", ['name_ar' => 'طاولة VIP', 'name_en' => 'VIP table'])
            ->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');
        $this->putJson("{$base}/activities/{$activityId}/resources/{$resourceId}", ['name_en' => 'Gold table', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name_en', 'Gold table')->assertJsonPath('data.is_active', false);
        $this->getJson("{$base}/activities/{$activityId}/resources")->assertOk()->assertJsonCount(5, 'data');
        $this->getJson("{$base}/activities/{$activityId}")->assertOk()->assertJsonPath('data.resources_count', 4)->assertJsonCount(5, 'data.resources');
        $this->deleteJson("{$base}/activities/{$activityId}/resources/{$resourceId}")->assertOk();

        // Activity rules.
        $this->putJson("{$base}/activities/{$activityId}", ['price_per_hour' => 90, 'max_slots' => 1])->assertStatus(422)->assertJsonValidationErrors('max_slots');
        $this->putJson("{$base}/activities/{$activityId}", ['price_per_hour' => 90])->assertOk()->assertJsonPath('data.slot_price', 45);
        $this->assertEquals(90.0, Venue::find($venueId)->min_price);

        // A sector the provider is not approved for is refused.
        $bowling = Sector::where('slug', 'bowling')->firstOrFail();
        $this->postJson("{$base}/activities", ['sector_id' => $bowling->id, 'name_ar' => 'بولينج', 'price_per_hour' => 120])
            ->assertForbidden()->assertJsonPath('code', 'sector_not_allowed');
        $this->putJson("{$base}/activities/{$activityId}", ['sector_id' => $bowling->id])->assertForbidden()->assertJsonPath('code', 'sector_not_allowed');
        $this->getJson("{$base}/activities")->assertOk()->assertJsonCount(1, 'data');

        // Gallery.
        $this->postJson("{$base}/media", ['url' => 'https://youtube.com/watch?v=abc'])->assertCreated()->assertJsonPath('data.type', 'video');
        $imageId = $this->post("{$base}/media", ['file' => UploadedFile::fake()->image('hall.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.type', 'image')->assertJsonPath('data.is_external', false)->json('data.id');
        $this->putJson("{$base}/media/order", ['ids' => [$imageId]])->assertOk()->assertJsonPath('data.0.id', $imageId);
        $this->getJson("{$base}/media")->assertOk()->assertJsonCount(2, 'data');

        // Cover upload through the multipart update route.
        $this->post($base, ['cover' => UploadedFile::fake()->image('cover.jpg'), 'summary_ar' => 'أفضل صالة'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.summary', 'أفضل صالة')->assertJsonPath('data.status.value', 'draft');
        $this->assertNotNull(Venue::find($venueId)->cover_image);

        // Submit → pending review; not visible to customers until approved.
        $this->postJson("{$base}/submit")->assertOk()->assertJsonPath('data.status.value', 'pending_review');
        $this->postJson("{$base}/submit")->assertStatus(422)->assertJsonPath('code', 'wrong_status');
        $this->getJson($base)->assertOk()->assertJsonCount(2, 'data.media')->assertJsonCount(1, 'data.activities')->assertJsonPath('data.rejection_reason', null);
        $this->getJson("/api/v1/venues/{$venueId}")->assertNotFound();

        // Approved → live; editing it sends it back to review, changing hours does not.
        app(VenueService::class)->approve(Venue::find($venueId), $provider->owner);
        $this->getJson("/api/v1/venues/{$venueId}")->assertOk();
        $this->assertSame(1, $provider->owner->notifications()->where('data->key', 'venue_approved')->count());
        $this->putJson("{$base}/hours", ['hours' => [['weekday' => 0, 'opens_at' => '12:00', 'closes_at' => '22:00']]])->assertOk();
        $this->assertSame(VenueStatus::Published, Venue::find($venueId)->status);
        $this->putJson($base, ['name_en' => 'Eight Ball Lounge'])->assertOk()->assertJsonPath('data.status.value', 'pending_review');

        $this->getJson('/api/v1/provider/venues')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $venueId);
        $this->getJson('/api/v1/provider/venues?status=published')->assertOk()->assertJsonCount(0, 'data');

        // A venue suspended by the platform is locked.
        app(VenueService::class)->suspend(Venue::find($venueId), $provider->owner, 'Complaints');
        $this->putJson($base, ['name_en' => 'x'])->assertStatus(422)->assertJsonPath('code', 'venue_locked');
        $this->putJson("{$base}/hours", ['hours' => [['weekday' => 0, 'is_closed' => true]]])->assertStatus(422)->assertJsonPath('code', 'venue_locked');
        $this->putJson("{$base}/activities/{$activityId}", ['price_per_hour' => 1])->assertStatus(422)->assertJsonPath('code', 'venue_locked');

        // Deleting: allowed while there are no orders.
        $this->deleteJson($base)->assertOk();
        $this->getJson($base)->assertNotFound();
    }

    public function test_things_with_bookings_cannot_be_deleted_or_regridded(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $activity = $this->activity($venue);
        $order = app(OrderService::class)->createOffline($provider, [$this->line($venue, 1)], ['name' => 'Walk-in'], 'cash', $provider->owner);
        $table = $order->bookings->first()->resource_id;
        $base = "/api/v1/provider/venues/{$venue->id}";

        Sanctum::actingAs($provider->owner);

        $this->deleteJson($base)->assertStatus(422)->assertJsonPath('code', 'venue_has_orders');
        $this->deleteJson("{$base}/activities/{$activity->id}")->assertStatus(422)->assertJsonPath('code', 'activity_has_bookings');
        $this->deleteJson("{$base}/activities/{$activity->id}/resources/{$table}")->assertStatus(422)->assertJsonPath('code', 'resource_has_bookings');
        $this->putJson("{$base}/activities/{$activity->id}", ['slot_minutes' => 30])->assertStatus(422)->assertJsonPath('code', 'activity_has_bookings');

        // Deactivating is always possible and stops new bookings only.
        $this->putJson("{$base}/activities/{$activity->id}/resources/{$table}", ['is_active' => false])->assertOk();
        $this->putJson("{$base}/activities/{$activity->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame('confirmed', $order->fresh()->status->value);
    }

    public function test_provider_cannot_access_another_providers_data(): void
    {
        $mine = $this->provider(null, 'Mine');
        $theirs = $this->provider(null, 'Theirs');
        $theirVenue = $this->venue($theirs);
        $order = app(OrderService::class)->createOffline($theirs, [$this->line($theirVenue, 1)], ['name' => 'Walk-in'], 'cash', $theirs->owner);

        Sanctum::actingAs($mine->owner);

        $this->getJson('/api/v1/provider/venues')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/provider/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/provider/orders/{$order->id}")->assertNotFound();
        $this->getJson("/api/v1/provider/venues/{$theirVenue->id}")->assertNotFound();
        $this->putJson("/api/v1/provider/venues/{$theirVenue->id}", ['name_ar' => 'x'])->assertNotFound();
        $this->postJson('/api/v1/provider/orders/offline', $this->line($theirVenue, 1, $this->slot(4)) + ['payment_method' => 'cash', 'customer' => ['name' => 'x']])
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->postJson('/api/v1/provider/bookings/check-in', ['code' => $order->bookings()->value('code')])
            ->assertNotFound()->assertJsonPath('code', 'booking_not_found');

        $this->assertSame(1, Order::count());
        $this->assertSame('confirmed', $order->bookings()->first()->status->value);

        // X-Provider-Id for a provider you are not a member of is refused.
        $this->getJson('/api/v1/provider/dashboard', ['X-Provider-Id' => $theirs->id])->assertForbidden();
        $this->getJson('/api/v1/provider/dashboard')->assertOk()->assertJsonPath('data.provider.id', $mine->id);
    }

    public function test_counter_booking_and_check_in(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $branch = $this->venue($provider, ['name_en' => 'Second branch']);
        $table = $this->activity($venue)->resources->first();
        Sanctum::actingAs($provider->owner);

        $response = $this->postJson('/api/v1/provider/orders/offline', [
            'activity_id' => $this->activity($venue)->id,
            'resource_id' => $table->id,
            'starts_at' => $this->slot()->toIso8601String(),
            'slots' => 2,
            'payment_method' => 'cash',
            // A provider cannot hang a counter sale on somebody's app account.
            'customer' => ['name' => 'عميل حضوري', 'phone' => '0501231234', 'user_id' => $provider->owner->id],
        ])->assertCreated()
            ->assertJsonPath('data.channel', 'offline')
            ->assertJsonPath('data.status.value', 'confirmed')
            ->assertJsonPath('data.total', 200)
            ->assertJsonPath('data.customer.name', 'عميل حضوري')
            ->assertJsonPath('data.offline_payment_method', 'cash')
            ->assertJsonPath('data.venue.id', $venue->id)
            ->assertJsonCount(1, 'data.bookings')
            ->assertJsonPath('data.bookings.0.status.value', 'confirmed')
            ->assertJsonPath('data.bookings.0.resource.id', $table->id);

        $order = Order::findOrFail($response->json('data.id'));
        $this->assertNull($order->user_id);
        $this->assertSame(2, BookingSlot::where('resource_id', $table->id)->count());

        // Offline sale: no sale entry, only the commission owed by the provider.
        $this->assertSame(0, LedgerEntry::where('order_id', $order->id)->where('type', 'sale')->count());
        $this->assertEquals(-20.0, (float) LedgerEntry::where('order_id', $order->id)->where('account', 'provider')->sum('amount'));

        // The same table cannot be sold twice, at the counter or in the app.
        $this->postJson('/api/v1/provider/orders/offline', $this->line($venue, 1, null, $table->id) + ['payment_method' => 'pos', 'customer' => ['name' => 'x']])
            ->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->postJson('/api/v1/provider/orders/offline', $this->line($venue, 1) + ['payment_method' => 'bitcoin'])
            ->assertStatus(422)->assertJsonValidationErrors(['payment_method', 'customer.name']);

        // Check-in: wrong branch, then the right one, then a second scan.
        $code = $response->json('data.bookings.0.code');
        $this->postJson('/api/v1/provider/bookings/check-in', ['code' => $code, 'venue_id' => $branch->id])
            ->assertNotFound()->assertJsonPath('code', 'booking_not_found');
        $this->postJson('/api/v1/provider/bookings/check-in', ['code' => strtolower($code), 'venue_id' => $venue->id], ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('message', 'Checked in successfully.')
            ->assertJsonPath('data.status.value', 'checked_in')
            ->assertJsonPath('data.name', 'Billiards - Table 1')
            ->assertJsonPath('data.order.number', $order->number);
        $this->postJson('/api/v1/provider/bookings/check-in', ['code' => $code])
            ->assertStatus(409)->assertJsonPath('code', 'booking_used');
        $this->postJson('/api/v1/provider/bookings/check-in', [])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->getJson('/api/v1/provider/orders')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/provider/orders?q={$code}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/provider/orders?venue_id={$branch->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/provider/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.provider_net', 180)
            ->assertJsonPath('data.bookings.0.status.value', 'checked_in');
        $this->getJson('/api/v1/provider/finance/balances')->assertOk()->assertJsonPath('data.pending', -20);
        $this->getJson('/api/v1/provider/finance/ledger')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/provider/dashboard')->assertOk()
            ->assertJsonPath('data.venues.total', 2)
            ->assertJsonPath('data.venues.by_status.published', 2)
            ->assertJsonPath('data.bookings.this_month', 1)
            ->assertJsonPath('data.orders.this_month', 1)
            ->assertJsonPath('data.sales.this_month', 200);
    }

    public function test_check_in_refuses_unpaid_cancelled_and_finished_bookings(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $orders = app(OrderService::class);

        $unpaid = $orders->createOnline($this->customer(), [$this->line($venue, 1)]);
        $paid = $orders->createOffline($provider, [$this->line($venue, 1, $this->slot(1))], ['name' => 'Walk-in'], 'cash', $provider->owner);

        Sanctum::actingAs($provider->owner);

        $this->postJson('/api/v1/provider/bookings/check-in', ['code' => $unpaid->bookings->first()->code])
            ->assertStatus(409)->assertJsonPath('code', 'booking_cancelled');
        $this->postJson('/api/v1/provider/bookings/check-in', ['code' => 'NOPE123456'])->assertNotFound();

        $this->travelTo($this->slot(2));
        $this->postJson('/api/v1/provider/bookings/check-in', ['code' => $paid->bookings->first()->code])
            ->assertStatus(409)->assertJsonPath('code', 'booking_finished');
        $this->assertNull(Booking::find($paid->bookings->first()->id)->checked_in_at);
    }

    public function test_counter_can_sell_a_slot_that_already_started_but_the_app_cannot(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);

        $this->travelTo(CarbonImmutable::now('Asia/Riyadh')->addDay()->setTime(18, 20));
        $running = CarbonImmutable::now('Asia/Riyadh')->setTime(18, 0)->utc();

        Sanctum::actingAs($this->customer());
        $this->postJson('/api/v1/bookings', $this->line($venue, 1, $running))->assertStatus(422)->assertJsonPath('code', 'slot_in_past');

        Sanctum::actingAs($provider->owner);
        $this->postJson('/api/v1/provider/orders/offline', $this->line($venue, 1, $running) + ['payment_method' => 'cash', 'customer' => ['name' => 'Walk-in']])
            ->assertCreated()->assertJsonPath('data.bookings.0.status.value', 'confirmed');
        $this->postJson('/api/v1/provider/orders/offline', $this->line($venue, 1, $running->subHours(2)) + ['payment_method' => 'cash', 'customer' => ['name' => 'Walk-in']])
            ->assertStatus(422)->assertJsonPath('code', 'slot_in_past');
    }

    public function test_day_schedule_and_slot_blocking(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider, [], [], 2);
        $activity = $this->activity($venue);
        [$tableA, $tableB] = $activity->resources->all();
        $date = $this->slot()->setTimezone('Asia/Riyadh')->format('Y-m-d');
        $base = "/api/v1/provider/venues/{$venue->id}";

        // One app booking (pending, held) on table A at 20:00-22:00.
        $customer = $this->customer('+966501112233', ['name' => 'Saad']);
        $order = app(OrderService::class)->createOnline($customer, [$this->line($venue, 2, null, $tableA->id)]);

        Sanctum::actingAs($provider->owner);

        // Block table B 18:00-20:00 for maintenance.
        $blocked = $this->postJson("{$base}/blocks", [
            'resource_id' => $tableB->id,
            'starts_at' => $this->slot(3, '18:00')->toIso8601String(),
            'slots' => 2,
            'reason' => 'صيانة',
        ])->assertCreated()
            ->assertJsonPath('data.blocked', 2)
            ->assertJsonCount(2, 'data.slots')
            ->assertJsonPath('data.slots.0.block_reason', 'صيانة');
        $slotId = $blocked->json('data.slots.0.slot_id');

        // Blocking over a booking or an existing block, or outside working hours, is refused.
        $this->postJson("{$base}/blocks", ['resource_id' => $tableA->id, 'starts_at' => $this->slot(3, '21:00')->toIso8601String(), 'slots' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->postJson("{$base}/blocks", ['resource_id' => $tableB->id, 'starts_at' => $this->slot(3, '19:00')->toIso8601String(), 'slots' => 3])
            ->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->postJson("{$base}/blocks", ['resource_id' => $tableB->id, 'starts_at' => $this->slot(3, '06:00')->toIso8601String(), 'slots' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'outside_hours');
        $this->assertSame(4, BookingSlot::count());

        $schedule = $this->getJson("{$base}/schedule?date={$date}", ['Accept-Language' => 'en'])->assertOk()
            ->assertJsonPath('data.date', $date)
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.opens_at', '10:00')
            ->assertJsonCount(1, 'data.activities')
            ->assertJsonPath('data.activities.0.id', $activity->id)
            ->assertJsonPath('data.activities.0.slot_minutes', 60)
            ->assertJsonCount(2, 'data.activities.0.resources')
            ->assertJsonCount(14, 'data.activities.0.resources.0.slots')
            // Table A: 19:00 free, 20:00 + 21:00 booked by Saad.
            ->assertJsonPath('data.activities.0.resources.0.id', $tableA->id)
            ->assertJsonPath('data.activities.0.resources.0.slots.9.status', 'available')
            ->assertJsonPath('data.activities.0.resources.0.slots.9.booking', null)
            ->assertJsonPath('data.activities.0.resources.0.slots.10.time', '20:00')
            ->assertJsonPath('data.activities.0.resources.0.slots.10.status', 'booked')
            ->assertJsonPath('data.activities.0.resources.0.slots.10.booking.id', $order->bookings->first()->id)
            ->assertJsonPath('data.activities.0.resources.0.slots.10.booking.status.value', 'pending')
            ->assertJsonPath('data.activities.0.resources.0.slots.10.booking.order.number', $order->number)
            ->assertJsonPath('data.activities.0.resources.0.slots.10.booking.customer.name', 'Saad')
            ->assertJsonPath('data.activities.0.resources.0.slots.11.status', 'booked')
            // Table B: 18:00 + 19:00 blocked, 20:00 free.
            ->assertJsonPath('data.activities.0.resources.1.slots.8.status', 'blocked')
            ->assertJsonPath('data.activities.0.resources.1.slots.8.slot_id', $slotId)
            ->assertJsonPath('data.activities.0.resources.1.slots.8.block_reason', 'صيانة')
            ->assertJsonPath('data.activities.0.resources.1.slots.9.status', 'blocked')
            ->assertJsonPath('data.activities.0.resources.1.slots.10.status', 'available');

        // Customers see blocked slots as booked and cannot take them.
        $this->getJson("/api/v1/activities/{$activity->id}/availability?date={$date}")->assertOk()
            ->assertJsonPath('data.resources.1.slots.8.status', 'booked');
        $this->postJson('/api/v1/provider/orders/offline', $this->line($venue, 1, $this->slot(3, '18:00'), $tableB->id) + ['payment_method' => 'cash', 'customer' => ['name' => 'x']])
            ->assertStatus(409)->assertJsonPath('code', 'slot_taken');

        // Unblock one slot; a slot held by a booking cannot be "unblocked".
        $this->deleteJson("{$base}/blocks/{$slotId}")->assertOk();
        $this->deleteJson("{$base}/blocks/{$slotId}")->assertNotFound();
        $held = BookingSlot::whereNotNull('booking_id')->value('id');
        $this->deleteJson("{$base}/blocks/{$held}")->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->getJson("{$base}/schedule?date={$date}&activity_id={$activity->id}")->assertOk()
            ->assertJsonPath('data.activities.0.resources.1.slots.8.status', 'available')
            ->assertJsonPath('data.activities.0.resources.1.slots.9.status', 'blocked');

        // Earlier slots of today are past; no date = today.
        $this->travelTo(CarbonImmutable::now('Asia/Riyadh')->setTime(15, 30));
        $this->getJson("{$base}/schedule")->assertOk()
            ->assertJsonPath('data.date', CarbonImmutable::now('Asia/Riyadh')->format('Y-m-d'))
            ->assertJsonPath('data.activities.0.resources.0.slots.4.status', 'past')        // 14:00-15:00
            ->assertJsonPath('data.activities.0.resources.0.slots.5.status', 'available');  // 15:00-16:00, still running

        // Staff without order / check-in access see the grid but not who booked.
        $staff = $this->customer('+966507770001');
        $provider->members()->create(['user_id' => $staff->id, 'role' => 'staff', 'permissions' => ['reviews.reply'], 'is_active' => true]);
        Sanctum::actingAs($staff);
        $this->getJson("{$base}/schedule?date={$date}")->assertOk()
            ->assertJsonPath('data.activities.0.resources.0.slots.10.status', 'booked')
            ->assertJsonPath('data.activities.0.resources.0.slots.10.booking.customer', null);
        $this->postJson("{$base}/blocks", ['resource_id' => $tableB->id, 'starts_at' => $this->slot(3, '22:00')->toIso8601String(), 'slots' => 1])
            ->assertForbidden()->assertJsonPath('code', 'missing_permission');
    }

    public function test_staff_permissions_are_enforced(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        Sanctum::actingAs($provider->owner);

        $memberId = $this->postJson('/api/v1/provider/members', ['phone' => '0507777777', 'name' => 'Door staff', 'role' => 'staff', 'permissions' => ['bookings.checkin']])
            ->assertCreated()
            ->assertJsonPath('data.permissions', ['bookings.checkin'])
            ->json('data.id');
        // The permission names of the old ticketing model are gone.
        $this->postJson('/api/v1/provider/members', ['phone' => '0507777778', 'role' => 'staff', 'permissions' => ['tickets.checkin']])
            ->assertStatus(422)->assertJsonValidationErrors('permissions.0');

        $staff = User::where('phone', '+966507777777')->firstOrFail();
        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/provider/orders')->assertForbidden()->assertJsonPath('code', 'missing_permission');
        $this->getJson('/api/v1/provider/finance/balances')->assertForbidden();
        $this->postJson('/api/v1/provider/venues', [])->assertForbidden();
        $this->putJson("/api/v1/provider/venues/{$venue->id}/hours", ['hours' => []])->assertForbidden();
        $this->postJson("/api/v1/provider/venues/{$venue->id}/activities", [])->assertForbidden();
        $this->postJson('/api/v1/provider/orders/offline', [])->assertForbidden();
        $this->getJson('/api/v1/provider/members')->assertForbidden();
        $this->getJson('/api/v1/provider/venues')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/provider/venues/{$venue->id}/schedule")->assertOk();
        $this->postJson('/api/v1/provider/bookings/check-in', ['code' => 'NOPE123456'])->assertNotFound();
        $this->getJson('/api/v1/provider/dashboard')->assertOk()->assertJsonPath('data.sales', null)->assertJsonPath('data.orders', null);

        // Deactivated staff lose access.
        Sanctum::actingAs($provider->owner);
        $this->deleteJson("/api/v1/provider/members/{$memberId}")->assertOk()->assertJsonPath('data.is_active', false);

        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/provider/dashboard')->assertForbidden();
    }

    public function test_provider_reviews_can_be_filtered_by_venue_and_replied_to(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $order = app(OrderService::class)->createOffline($provider, [$this->line($venue, 1)], ['name' => 'Walk-in'], 'cash', $provider->owner);
        $review = $provider->reviews()->create(['user_id' => $this->customer()->id, 'venue_id' => $venue->id, 'order_id' => $order->id, 'rating' => 4, 'status' => 'published']);

        Sanctum::actingAs($provider->owner);

        $this->getJson("/api/v1/provider/reviews?venue_id={$venue->id}", ['Accept-Language' => 'en'])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.venue', ['id' => $venue->id, 'name' => 'Test venue'])
            ->assertJsonPath('meta.rating_count', 1);
        $this->getJson('/api/v1/provider/reviews?venue_id=999999')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/provider/reviews/{$review->id}/reply", ['reply' => 'شكراً لك'])->assertOk()->assertJsonPath('data.provider_reply', 'شكراً لك');
    }
}
