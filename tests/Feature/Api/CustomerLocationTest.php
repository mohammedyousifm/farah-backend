<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\HomeSection;
use Laravel\Sanctum\Sanctum;

/** The customer's GPS point (saved from the profile), search by city name, and "nearest first" everywhere. */
class CustomerLocationTest extends ApiTestCase
{
    public function test_profile_saves_the_gps_point_and_its_city(): void
    {
        $other = City::where('id', '!=', $this->city->id)->whereNotNull('lat')->firstOrFail();
        Sanctum::actingAs($this->customer('+966501111111', ['city_id' => $this->city->id]));

        $this->putJson('/api/v1/me', ['lat' => $other->lat + 0.01, 'lng' => $other->lng])->assertOk()
            ->assertJsonPath('data.city_id', $other->id)
            ->assertJsonPath('data.location.lat', round($other->lat + 0.01, 7));

        $this->putJson('/api/v1/me', ['lat' => 24.7])->assertStatus(422)->assertJsonValidationErrors('lng');

        $this->putJson('/api/v1/me', ['lat' => null, 'lng' => null])->assertOk()->assertJsonPath('data.location', null);
    }

    public function test_search_matches_the_city_name(): void
    {
        $other = City::where('id', '!=', $this->city->id)->firstOrFail();
        $here = $this->venue($this->provider());
        $there = $this->venue($this->provider(null, 'Other'), ['city_id' => $other->id]);

        $ids = fn ($response) => collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$there->id], $ids($this->getJson('/api/v1/venues?q='.urlencode($other->name_ar))));
        $this->assertSame([$here->id], $ids($this->getJson('/api/v1/venues?q='.urlencode($this->city->name_en))));
    }

    public function test_saved_location_lists_the_nearest_first_in_search_and_home(): void
    {
        $far = City::where('id', '!=', $this->city->id)->whereNotNull('lat')->firstOrFail();
        $near = $this->venue($this->provider(), ['lat' => $far->lat, 'lng' => $far->lng]);
        $away = $this->venue($this->provider(null, 'Other'), ['lat' => $this->city->lat, 'lng' => $this->city->lng, 'is_featured' => true, 'rating_avg' => 5]);
        HomeSection::create(['title_ar' => 'الأعلى تقييمًا', 'type' => 'venues', 'layout' => 'carousel', 'source' => 'top_rated', 'sort_order' => 1]);

        Sanctum::actingAs($this->customer('+966501111111', ['lat' => $far->lat, 'lng' => $far->lng]));

        // No point in the request: the saved one is used, nearest first, every city (no radius).
        $search = $this->getJson('/api/v1/venues')->assertOk();
        $this->assertSame([$near->id, $away->id], collect($search->json('data'))->pluck('id')->all());
        $this->assertEquals(0.0, $search->json('data.0.distance_km'));

        $this->getJson('/api/v1/home')->assertOk()
            ->assertJsonPath('data.0.items.0.id', $near->id)
            ->assertJsonPath('data.0.items.1.id', $away->id);

        // A point sent by the phone wins over the saved one.
        $this->getJson("/api/v1/venues?lat={$this->city->lat}&lng={$this->city->lng}")->assertJsonPath('data.0.id', $away->id);
    }
}
