<?php

namespace Tests\Feature\Api;

use App\Enums\UserType;
use App\Enums\VenueStatus;
use App\Models\Activity;
use App\Models\User;
use App\Services\KpiService;
use App\Services\VenueService;
use Laravel\Sanctum\Sanctum;

/**
 * A store is reviewed as a whole before it goes live; after that, every activity the provider
 * adds waits for its own review and is hidden from customers until it is approved.
 */
class ActivityReviewTest extends ApiTestCase
{
    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@farah.sa', 'password' => 'password', 'type' => UserType::Admin, 'status' => 'active']);
    }

    public function test_activity_added_to_a_live_store_waits_for_review(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        Sanctum::actingAs($provider->owner);
        $base = "/api/v1/provider/venues/{$venue->id}/activities";

        $id = $this->postJson($base, ['sector_id' => $this->sector->id, 'name_ar' => 'سنوكر', 'price_per_hour' => 120, 'resources_count' => 2, 'is_active' => true])
            ->assertCreated()
            ->assertJsonPath('data.review_status', 'pending')
            ->assertJsonPath('data.is_active', false)
            ->json('data.id');

        // Not approved yet: the provider cannot switch it on, and customers do not see it.
        $this->putJson("{$base}/{$id}", ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', false);
        $names = collect($this->getJson("/api/v1/venues/{$venue->id}")->assertOk()->json('data.activities'))->pluck('id');
        $this->assertNotContains($id, $names);
        $this->getJson("/api/v1/activities/{$id}/availability?date=".now('Asia/Riyadh')->addDays(2)->toDateString())->assertNotFound();
        $this->assertSame(1, app(KpiService::class)->queue()['activities']);

        // Approved: live and bookable, and the owner is told.
        app(VenueService::class)->approveActivity(Activity::find($id), $this->admin());
        $this->assertSame([Activity::APPROVED, true], [Activity::find($id)->review_status, Activity::find($id)->is_active]);
        $this->assertSame(1, $provider->owner->notifications()->where('data->key', 'activity_approved')->count());
        $this->getJson("/api/v1/activities/{$id}/availability?date=".now('Asia/Riyadh')->addDays(2)->toDateString())->assertOk();
    }

    public function test_rejected_activity_goes_back_to_review_when_edited(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        Sanctum::actingAs($provider->owner);
        $base = "/api/v1/provider/venues/{$venue->id}/activities";
        $id = $this->postJson($base, ['sector_id' => $this->sector->id, 'name_ar' => 'سنوكر', 'price_per_hour' => 120])->assertCreated()->json('data.id');

        app(VenueService::class)->rejectActivity(Activity::find($id), $this->admin(), 'السعر غير واضح');
        $this->getJson("{$base}/{$id}")->assertOk()->assertJsonPath('data.review_status', 'rejected')->assertJsonPath('data.review_note', 'السعر غير واضح');
        $this->assertSame(1, $provider->owner->notifications()->where('data->key', 'activity_rejected')->count());

        $this->putJson("{$base}/{$id}", ['price_per_hour' => 90])->assertOk()
            ->assertJsonPath('data.review_status', 'pending')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_activities_of_a_store_that_is_not_live_yet_are_reviewed_with_the_store(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider, ['status' => VenueStatus::Draft, 'published_at' => null]);
        Sanctum::actingAs($provider->owner);

        $this->postJson("/api/v1/provider/venues/{$venue->id}/activities", ['sector_id' => $this->sector->id, 'name_ar' => 'سنوكر', 'price_per_hour' => 120])
            ->assertCreated()
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.is_active', true);
        $this->assertSame(0, app(KpiService::class)->queue()['activities']);
    }
}
