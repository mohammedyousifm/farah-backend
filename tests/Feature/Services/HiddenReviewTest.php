<?php

namespace Tests\Feature\Services;

use App\Models\Review;
use App\Services\ReviewModerationService;
use Laravel\Sanctum\Sanctum;

/** TODAY-03: a review hidden in Admin must disappear from every customer-facing list, count and average. */
class HiddenReviewTest extends ServiceTestCase
{
    public function test_hidden_review_is_left_out_of_the_customer_side_until_published_again(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $make = fn (int $rating, string $comment) => Review::create([
            'user_id' => $this->paidOrder($venue, 1)->user_id, 'venue_id' => $venue->id, 'provider_id' => $provider->id,
            'rating' => $rating, 'comment' => $comment, 'status' => Review::PUBLISHED,
        ]);
        $good = $make(5, 'great place');
        $bad = $make(1, 'abusive words');

        $service = app(ReviewModerationService::class);
        $admin = $this->admin();
        $service->hide($bad, $admin, 'abuse');
        $this->assertSame(Review::HIDDEN, $bad->fresh()->status); // persists, not just a badge

        $response = $this->getJson("/api/v1/venues/{$venue->id}/reviews")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $good->id)
            ->assertJsonPath('meta.rating_count', 1)
            ->assertJsonPath('meta.total', 1);
        $this->assertEquals(5, $response->json('meta.rating_avg'));
        $this->assertSame([5 => 1], array_map('intval', $response->json('meta.distribution')));
        $this->assertStringNotContainsString('abusive words', $response->getContent());

        // The venue card / details carry the same aggregate.
        $this->getJson("/api/v1/venues/{$venue->id}")->assertOk()->assertJsonPath('data.rating_count', 1);

        // The provider cannot reply to it (the reply would notify the customer about a hidden review).
        Sanctum::actingAs($provider->owner);
        $this->getJson('/api/v1/provider/reviews')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/provider/reviews/{$bad->id}/reply", ['reply' => 'x'])->assertNotFound();
        $this->assertBusiness('review_hidden', fn () => $service->reply($bad->fresh(), 'x'));

        // Only an explicit publish brings it back.
        $service->publish($bad->fresh(), $admin);
        $this->getJson("/api/v1/venues/{$venue->id}/reviews")->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.rating_count', 2);
    }
}
