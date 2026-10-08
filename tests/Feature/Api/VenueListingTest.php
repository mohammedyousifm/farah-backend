<?php

namespace Tests\Feature\Api;

use App\Enums\VenueStatus;
use App\Models\City;
use App\Models\HomeSection;
use App\Models\Sector;
use App\Services\VenueService;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/** Discovery: catalog, venue search / filters / sorting, venue details and free slots. */
class VenueListingTest extends ApiTestCase
{
    public function test_public_catalog_endpoints_respond(): void
    {
        $this->venue($this->provider());

        $this->getJson('/api/v1/app/config')->assertOk()
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.timezone', 'Asia/Riyadh')
            ->assertJsonPath('data.max_bookings_per_order', 4);
        $this->getJson('/api/v1/cities')->assertOk()->assertJsonStructure(['data' => [['id', 'name', 'name_ar', 'name_en']]]);
        $this->getJson('/api/v1/categories?sector_id='.$this->sector->id)->assertOk();
        $this->getJson('/api/v1/recommendations')->assertOk()->assertJsonCount(1, 'data');

        // Sectors are the activity types, with how many bookable venues offer each.
        $sectors = $this->getJson('/api/v1/sectors')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'slug', 'name', 'icon', 'image_url', 'venues_count']]]);
        $counts = collect($sectors->json('data'))->pluck('venues_count', 'slug');
        $this->assertSame(1, $counts['billiards']);
        $this->assertSame(0, $counts['bowling']);
    }

    public function test_home_feed_lists_venue_sections(): void
    {
        $venue = $this->venue($this->provider(), ['is_featured' => true]);
        HomeSection::create(['title_ar' => 'مميز', 'title_en' => 'Featured', 'type' => 'venues', 'layout' => 'carousel', 'source' => 'featured', 'sort_order' => 1]);
        HomeSection::create(['title_ar' => 'الأنشطة', 'type' => 'sectors', 'layout' => 'grid', 'source' => 'latest', 'sort_order' => 2]);
        $banner = HomeSection::create(['type' => 'banner', 'layout' => 'hero', 'source' => 'manual', 'sort_order' => 0]);
        $banner->items()->create(['title_ar' => 'عرض', 'media_type' => 'image', 'media_url' => 'https://example.com/b.jpg', 'link_type' => 'venue', 'link_value' => $venue->id]);

        $this->getJson('/api/v1/home', ['Accept-Language' => 'en'])->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.type', 'banner')
            ->assertJsonPath('data.0.items.0.link.type', 'venue')
            ->assertJsonPath('data.1.type', 'venues')
            ->assertJsonPath('data.1.title', 'Featured')
            ->assertJsonPath('data.1.items.0.kind', 'venue')
            ->assertJsonPath('data.1.items.0.id', $venue->id)
            ->assertJsonPath('data.2.type', 'sectors')
            ->assertJsonPath('data.2.items.0.kind', 'sector');
    }

    public function test_listing_filters_and_sorting(): void
    {
        $provider = $this->provider();
        $other = $this->provider(null, 'Other');
        $bowling = Sector::where('slug', 'bowling')->firstOrFail();
        $otherCity = City::where('id', '!=', $this->city->id)->firstOrFail();

        $cheap = $this->venue($provider, ['name_ar' => 'صالة البلياردو', 'name_en' => 'Cue Club', 'is_featured' => true, 'published_at' => now()->subDay()], ['price_per_hour' => 50]);
        $pricey = $this->venue($other, ['name_ar' => 'مركز البولينج', 'name_en' => 'Strike Lanes', 'city_id' => $otherCity->id, 'rating_avg' => 4.8, 'rating_count' => 10],
            ['sector_id' => $bowling->id, 'name_ar' => 'بولينج', 'name_en' => 'Bowling', 'price_per_hour' => 300]);
        $draft = $this->venue($provider, ['name_ar' => 'مسودة', 'status' => VenueStatus::Draft]);
        $suspended = $this->venue($provider, ['name_ar' => 'موقوف', 'status' => VenueStatus::Suspended]);

        $ids = fn ($response) => collect($response->json('data'))->pluck('id')->all();

        // Only visible venues, paginated envelope.
        $all = $this->getJson('/api/v1/venues')->assertOk()->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'total']]);
        $this->assertSame([$cheap->id, $pricey->id], $ids($all)); // recommended: featured first
        $this->assertNotContains($draft->id, $ids($all));
        $this->assertNotContains($suspended->id, $ids($all));

        $this->assertSame([$pricey->id], $ids($this->getJson("/api/v1/venues?city_id={$otherCity->id}")));
        $this->assertSame([$cheap->id], $ids($this->getJson('/api/v1/venues?q=Cue')));
        $this->assertSame([$pricey->id], $ids($this->getJson('/api/v1/venues?q=Bowling'))); // matches the activity name
        $this->assertSame([$cheap->id], $ids($this->getJson('/api/v1/venues?featured=1')));
        $this->assertSame([$pricey->id], $ids($this->getJson('/api/v1/venues?price_min=100&price_max=500')));
        $this->assertSame([$pricey->id], $ids($this->getJson("/api/v1/venues?sector_id={$bowling->id}")));
        $this->assertSame([$cheap->id], $ids($this->getJson("/api/v1/venues?sector_id={$this->sector->id}")));
        $this->assertSame([$pricey->id], $ids($this->getJson("/api/v1/venues?provider_id={$other->id}")));
        $this->assertSame([$cheap->id, $pricey->id], $ids($this->getJson('/api/v1/venues?sort=price')));
        $this->assertSame([$pricey->id, $cheap->id], $ids($this->getJson('/api/v1/venues?sort=rating')));
        $this->assertSame([$pricey->id, $cheap->id], $ids($this->getJson('/api/v1/venues?sort=latest&per_page=5')->assertJsonPath('meta.per_page', 5)));

        $this->getJson('/api/v1/venues?sort=bogus')->assertStatus(422)->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/venues?sort=upcoming')->assertStatus(422);
    }

    public function test_geo_filter_and_nearest_sort(): void
    {
        $provider = $this->provider();
        $far = City::where('id', '!=', $this->city->id)->firstOrFail();
        $near = $this->venue($provider, ['lat' => $this->city->lat, 'lng' => $this->city->lng]);
        $away = $this->venue($provider, ['lat' => $far->lat, 'lng' => $far->lng]);
        $this->venue($provider); // no coordinates: never part of a geo search

        $ids = fn ($response) => collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$near->id], $ids($this->getJson("/api/v1/venues?lat={$this->city->lat}&lng={$this->city->lng}&radius=5")));

        $sorted = $this->getJson("/api/v1/venues?lat={$far->lat}&lng={$far->lng}&radius=500&sort=nearest")->assertOk();
        $this->assertSame($away->id, $ids($sorted)[0]);
        $this->assertEquals(0.0, $sorted->json('data.0.distance_km'));

        $this->getJson('/api/v1/venues?lat=24.7')->assertStatus(422)->assertJsonValidationErrors('lng');
    }

    public function test_venue_card_shape(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider, ['address' => 'طريق الملك فهد', 'lat' => 24.7, 'lng' => 46.7]);
        $customer = $this->customer();
        $customer->favorites()->attach($venue->id);

        // Wednesday 21:00 Riyadh: inside the default 10:00 -> 00:00 hours.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 21:00', 'Asia/Riyadh'));
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/venues', ['Accept-Language' => 'en'])->assertOk()
            ->assertJsonStructure(['data' => [['id', 'slug', 'name', 'summary', 'cover_url', 'min_price', 'currency', 'is_featured', 'rating_avg',
                'rating_count', 'address', 'lat', 'lng', 'distance_km', 'is_favorite', 'is_open_now', 'today_hours',
                'city' => ['id', 'name'], 'sectors' => [['id', 'slug', 'name', 'icon']], 'provider' => ['id', 'name', 'logo_url']]]])
            ->assertJsonPath('data.0.name', 'Test venue')
            ->assertJsonPath('data.0.min_price', 100)
            ->assertJsonPath('data.0.is_favorite', true)
            ->assertJsonPath('data.0.is_open_now', true)
            ->assertJsonPath('data.0.today_hours', ['opens_at' => '10:00', 'closes_at' => '00:00'])
            ->assertJsonPath('data.0.sectors.0.slug', 'billiards')
            ->assertJsonPath('data.0.provider.id', $provider->id);

        // 03:00: closed, and a day marked closed has no hours.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 03:00', 'Asia/Riyadh'));
        $this->getJson('/api/v1/venues')->assertJsonPath('data.0.is_open_now', false);

        app(VenueService::class)->syncHours($venue, [['weekday' => 4, 'is_closed' => true]]); // Thursday
        $this->travelTo(CarbonImmutable::parse('2026-10-08 21:00', 'Asia/Riyadh'));
        $this->getJson('/api/v1/venues')->assertJsonPath('data.0.is_open_now', false)->assertJsonPath('data.0.today_hours', null);
    }

    public function test_open_past_midnight_counts_as_open_now(): void
    {
        $venue = $this->venue($this->provider());
        app(VenueService::class)->syncHours($venue, array_map(
            fn (int $d) => ['weekday' => $d, 'opens_at' => '16:00', 'closes_at' => '02:00'], range(0, 6)));

        $this->travelTo(CarbonImmutable::parse('2026-10-08 01:00', 'Asia/Riyadh'));
        $this->getJson('/api/v1/venues')->assertJsonPath('data.0.is_open_now', true);

        $this->travelTo(CarbonImmutable::parse('2026-10-08 03:00', 'Asia/Riyadh'));
        $this->getJson('/api/v1/venues')->assertJsonPath('data.0.is_open_now', false);
    }

    public function test_venue_detail_by_id_and_slug_with_locale(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider, ['phone' => '0112223344']);
        $activity = $this->activity($venue);
        $activity->resources()->first()->update(['is_active' => false]);
        $venue->activities()->create(['sector_id' => $this->sector->id, 'name_ar' => 'متوقف', 'price_per_hour' => 10, 'is_active' => false]);
        $venue->media()->create(['type' => 'image', 'url' => 'https://example.com/a.jpg', 'sort_order' => 0]);

        $this->getJson("/api/v1/venues/{$venue->id}", ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Test venue')
            ->assertJsonPath('data.phone', '0112223344')
            ->assertJsonPath('data.is_bookable', true)
            ->assertJsonPath('data.advance_booking_days', 14)
            ->assertJsonPath('data.refund_policy.key', 'flexible')
            ->assertJsonPath('data.refund_policy.label', 'Flexible')
            ->assertJsonPath('data.media.0.type', 'image')
            ->assertJsonCount(7, 'data.hours')
            ->assertJsonPath('data.hours.0', ['weekday' => 0, 'day' => 'Sunday', 'opens_at' => '10:00', 'closes_at' => '00:00', 'is_closed' => false])
            // Inactive activities and resources are hidden from customers.
            ->assertJsonCount(1, 'data.activities')
            ->assertJsonPath('data.activities.0.id', $activity->id)
            ->assertJsonPath('data.activities.0.name', 'Billiards')
            ->assertJsonPath('data.activities.0.sector.slug', 'billiards')
            ->assertJsonPath('data.activities.0.price_per_hour', 100)
            ->assertJsonPath('data.activities.0.slot_minutes', 60)
            ->assertJsonPath('data.activities.0.slot_price', 100)
            ->assertJsonPath('data.activities.0.min_slots', 1)
            ->assertJsonPath('data.activities.0.max_slots', 4)
            ->assertJsonPath('data.activities.0.resources_count', 2)
            ->assertJsonCount(2, 'data.activities.0.resources')
            ->assertJsonPath('data.activities.0.resources.0.name', 'Table 2')
            ->assertJsonMissingPath('data.rejection_reason');

        $this->getJson("/api/v1/venues/{$venue->slug}", ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.name', 'مركز تجريبي')
            ->assertJsonPath('data.hours.0.day', 'الأحد');

        $this->assertSame(2, $venue->fresh()->views_count);

        $this->getJson('/api/v1/venues/999999')->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->getJson("/api/v1/venues/{$venue->id}/reviews")->assertOk()->assertJsonPath('meta.rating_count', 0);
        $this->getJson("/api/v1/venues/{$venue->id}/similar")->assertOk();
        $this->getJson("/api/v1/providers/{$provider->id}")->assertOk()->assertJsonPath('data.venues.0.id', $venue->id);
    }

    public function test_unpublished_venues_are_hidden_everywhere(): void
    {
        $provider = $this->provider();
        $draft = $this->venue($provider, ['status' => VenueStatus::PendingReview]);
        $activity = $this->activity($draft);

        $this->getJson("/api/v1/venues/{$draft->id}")->assertNotFound();
        $this->getJson("/api/v1/venues/{$draft->slug}")->assertNotFound();
        $this->getJson("/api/v1/venues/{$draft->id}/reviews")->assertNotFound();
        $this->getJson("/api/v1/venues/{$draft->id}/similar")->assertNotFound();
        $this->getJson("/api/v1/activities/{$activity->id}/availability")->assertNotFound();

        // A published venue of a provider that is no longer approved disappears too.
        $live = $this->venue($provider);
        $this->getJson("/api/v1/venues/{$live->id}")->assertOk();
        $provider->update(['status' => 'suspended']);
        $this->getJson("/api/v1/venues/{$live->id}")->assertNotFound();
        $this->getJson('/api/v1/venues')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_availability_lists_every_slot_per_resource(): void
    {
        $venue = $this->venue($this->provider());
        $activity = $this->activity($venue);
        $table = $activity->resources->first();
        $date = $this->slot()->setTimezone('Asia/Riyadh')->format('Y-m-d');

        Sanctum::actingAs($this->customer());
        $this->postJson('/api/v1/bookings', $this->line($venue, 2, null, $table->id))->assertCreated();

        $response = $this->getJson("/api/v1/activities/{$activity->id}/availability?date={$date}", ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.date', $date)
            ->assertJsonPath('data.timezone', 'Asia/Riyadh')
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.opens_at', '10:00')
            ->assertJsonPath('data.closes_at', '00:00')
            ->assertJsonPath('data.slot_minutes', 60)
            ->assertJsonPath('data.slot_price', 100)
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.min_slots', 1)
            ->assertJsonPath('data.max_slots', 4)
            ->assertJsonCount(3, 'data.resources')
            ->assertJsonCount(14, 'data.resources.0.slots') // 10:00 -> 00:00, hourly
            ->assertJsonPath('data.resources.0.id', $table->id)
            ->assertJsonPath('data.resources.0.name', 'Table 1')
            ->assertJsonPath('data.resources.0.slots.0.time', '10:00')
            ->assertJsonPath('data.resources.0.slots.0.end_time', '11:00')
            ->assertJsonPath('data.resources.0.slots.0.status', 'available')
            ->assertJsonPath('data.resources.0.slots.10.time', '20:00')
            ->assertJsonPath('data.resources.0.slots.10.status', 'booked')
            ->assertJsonPath('data.resources.0.slots.11.status', 'booked')
            ->assertJsonPath('data.resources.0.slots.12.status', 'available')
            ->assertJsonPath('data.resources.1.slots.10.status', 'available');

        // starts_at is the same instant in UTC.
        $this->assertTrue($this->slot()->equalTo(CarbonImmutable::parse($response->json('data.resources.0.slots.10.starts_at'))));

        // Beyond the advance booking window the day is listed but closed for booking.
        $far = CarbonImmutable::now('Asia/Riyadh')->addDays(40)->format('Y-m-d');
        $this->getJson("/api/v1/activities/{$activity->id}/availability?date={$far}")->assertOk()
            ->assertJsonPath('data.resources.0.slots.0.status', 'closed');

        // Earlier today: past. No date = today.
        $this->travelTo(CarbonImmutable::now('Asia/Riyadh')->setTime(15, 30));
        $this->getJson("/api/v1/activities/{$activity->id}/availability")->assertOk()
            ->assertJsonPath('data.date', CarbonImmutable::now('Asia/Riyadh')->format('Y-m-d'))
            ->assertJsonPath('data.resources.0.slots.5.status', 'past')       // 15:00
            ->assertJsonPath('data.resources.0.slots.6.status', 'available'); // 16:00

        $this->getJson("/api/v1/activities/{$activity->id}/availability?date=tomorrow")->assertStatus(422)->assertJsonValidationErrors('date');
        $this->getJson('/api/v1/activities/999999/availability')->assertNotFound();
    }

    public function test_availability_on_a_closed_day(): void
    {
        $venue = $this->venue($this->provider());
        $day = CarbonImmutable::now('Asia/Riyadh')->addDays(3);
        app(VenueService::class)->syncHours($venue, [['weekday' => $day->dayOfWeek, 'is_closed' => true]]);

        $this->getJson("/api/v1/activities/{$this->activity($venue)->id}/availability?date=".$day->format('Y-m-d'))->assertOk()
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.opens_at', null)
            ->assertJsonCount(3, 'data.resources')
            ->assertJsonCount(0, 'data.resources.0.slots');
    }

    public function test_favorites(): void
    {
        $venue = $this->venue($this->provider());
        $hidden = $this->venue($this->provider(null, 'Hidden'), ['status' => VenueStatus::Draft]);
        Sanctum::actingAs($this->customer());

        $this->postJson("/api/v1/favorites/{$venue->id}")->assertCreated()->assertJsonPath('data', ['venue_id' => $venue->id, 'is_favorite' => true]);
        $this->postJson("/api/v1/favorites/{$venue->id}")->assertCreated(); // idempotent
        $this->postJson("/api/v1/favorites/{$hidden->id}")->assertNotFound();

        $this->getJson('/api/v1/favorites')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $venue->id)->assertJsonPath('data.0.is_favorite', true);
        $this->getJson("/api/v1/venues/{$venue->id}")->assertJsonPath('data.is_favorite', true);
        $this->getJson('/api/v1/recommendations')->assertJsonPath('data.0.is_favorite', true);

        $this->deleteJson("/api/v1/favorites/{$venue->id}")->assertOk()->assertJsonPath('data.is_favorite', false);
        $this->getJson('/api/v1/favorites')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_similar_venues_share_the_activity_type(): void
    {
        $bowling = Sector::where('slug', 'bowling')->firstOrFail();
        $venue = $this->venue($this->provider());
        $same = $this->venue($this->provider(null, 'Same'));
        $different = $this->venue($this->provider(null, 'Different'), [], ['sector_id' => $bowling->id]);

        $ids = collect($this->getJson("/api/v1/venues/{$venue->id}/similar")->assertOk()->json('data'))->pluck('id');

        $this->assertSame($same->id, $ids->first());
        $this->assertNotContains($venue->id, $ids);
        $this->assertContains($different->id, $ids); // lower score (latest rule only), still listed
    }
}
