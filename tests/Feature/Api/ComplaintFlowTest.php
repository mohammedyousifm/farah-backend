<?php

namespace Tests\Feature\Api;

use App\Enums\UserType;
use App\Models\Complaint;
use App\Models\User;
use App\Services\ComplaintService;
use App\Services\OrderService;
use Laravel\Sanctum\Sanctum;

/** A complaint sent from the app is the one the admin team answers, and the answer comes back to the app. */
class ComplaintFlowTest extends ApiTestCase
{
    public function test_a_complaint_about_an_order_reaches_the_team_and_their_reply_reaches_the_customer(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $customer = $this->customer();
        $order = app(OrderService::class)->createOnline($customer, [$this->line($venue, 1)]);
        $agent = User::create(['name' => 'Support Agent', 'email' => 'agent@farah.sa', 'password' => 'secret-pass-123', 'type' => UserType::Admin]);

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/complaints')->assertOk()->assertJsonCount(0, 'data');

        $id = $this->postJson('/api/v1/complaints', [
            'type' => 'complaint', 'subject' => 'The table was not ready', 'body' => 'I arrived on time and waited half an hour.', 'order_id' => $order->id,
        ])->assertCreated()
            ->assertJsonPath('data.status.value', 'open')
            ->assertJsonPath('data.order_number', $order->number)
            ->assertJsonPath('data.provider_id', $provider->id)
            ->json('data.id');

        // The dashboard works on the same record, linked to the order, the place and the provider.
        $complaint = Complaint::findOrFail($id);
        $this->assertSame([$order->id, $venue->id, $provider->id], [$complaint->order_id, $complaint->venue_id, $complaint->provider_id]);

        app(ComplaintService::class)->reply($complaint, $agent, 'Sorry about that, we contacted the place.');
        app(ComplaintService::class)->reply($complaint, $agent, 'Internal: call the provider.', internal: true);

        $this->getJson("/api/v1/complaints/{$id}")->assertOk()
            ->assertJsonPath('data.status.value', 'in_progress')
            ->assertJsonCount(1, 'data.replies')
            ->assertJsonPath('data.replies.0.from_support', true)
            ->assertJsonPath('data.replies.0.body', 'Sorry about that, we contacted the place.');
        $this->assertSame(1, $customer->notifications()->where('data->key', 'complaint_replied')->count());

        $this->postJson("/api/v1/complaints/{$id}/replies", ['body' => 'Thank you.'])->assertOk()->assertJsonCount(2, 'data.replies');

        app(ComplaintService::class)->resolve($complaint->fresh(), $agent, 'One free hour was added.');
        $this->getJson('/api/v1/complaints')->assertOk()
            ->assertJsonPath('data.0.status.value', 'resolved')
            ->assertJsonPath('data.0.resolution', 'One free hour was added.');

        app(ComplaintService::class)->close($complaint->fresh(), $agent);
        $this->postJson("/api/v1/complaints/{$id}/replies", ['body' => 'Again'])->assertStatus(422)->assertJsonPath('code', 'complaint_closed');
    }
}
