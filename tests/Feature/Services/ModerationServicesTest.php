<?php

namespace Tests\Feature\Services;

use App\Enums\BookingStatus;
use App\Enums\ComplaintStatus;
use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\BookingSlot;
use App\Models\Complaint;
use App\Models\Review;
use App\Services\ComplaintService;
use App\Services\DisputeService;
use App\Services\ReviewModerationService;

class ModerationServicesTest extends ServiceTestCase
{
    public function test_dispute_workflow_with_refund(): void
    {
        $order = $this->paidOrder($this->venue($this->provider()), 2);
        $admin = $this->admin();
        $service = app(DisputeService::class);

        $dispute = $service->open($order, $admin, 'not_as_described', 'Table was not as shown', 150);
        $service->startReview($dispute, $admin);
        $this->assertSame(DisputeStatus::UnderReview, $dispute->fresh()->status);
        $this->assertSame($admin->id, $dispute->fresh()->assigned_to);
        $this->assertBusiness('invalid_transition', fn () => $service->startReview($dispute->fresh(), $admin));
        $this->assertBusiness('refund_not_allowed', fn () => $service->resolve($dispute, $admin, DisputeStatus::ResolvedProvider, 'ok', 10));

        $resolved = $service->resolve($dispute, $admin, DisputeStatus::ResolvedCustomer, 'Partial refund agreed', 150);

        $this->assertSame(DisputeStatus::ResolvedCustomer, $resolved->status);
        $this->assertNotNull($resolved->refund_id);
        $this->assertEquals(150.0, $order->fresh()->refunded_amount);
        $this->assertSame(OrderStatus::PartiallyRefunded, $order->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $order->bookings()->first()->status); // compensation, the booking stands
        $this->assertBusiness('invalid_transition', fn () => $service->resolve($resolved, $admin, DisputeStatus::Closed, 'again'));
        $this->assertSame(1, $order->user->notifications()->where('data->key', 'dispute_resolved')->count());
        $this->assertTrue(AuditLog::where('action', 'dispute.resolved')->where('auditable_id', $dispute->id)->exists());

        $service->reopen($resolved, $admin, 'new evidence');
        $this->assertSame(DisputeStatus::UnderReview, $dispute->fresh()->status);
    }

    public function test_dispute_resolved_with_a_full_refund_frees_the_table(): void
    {
        $order = $this->paidOrder($this->venue($this->provider()), 2);
        $admin = $this->admin();
        $service = app(DisputeService::class);

        $service->resolve($service->open($order, $admin, 'venue_closed', 'Arrived and the place was closed'), $admin, DisputeStatus::ResolvedCustomer, 'Full refund', 200);

        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
        $this->assertSame(BookingStatus::Cancelled, $order->bookings()->first()->status);
        $this->assertSame(0, BookingSlot::count());
    }

    public function test_dispute_refund_failure_reverts_the_resolution(): void
    {
        $order = $this->paidOrder($this->venue($this->provider()), 1);
        $order->user->delete(); // no account, so no wallet: the refund goes back through the gateway
        $admin = $this->admin();
        $this->failingRefunds();
        $service = app(DisputeService::class);
        $dispute = $service->open($order, $admin, 'no_show');

        $this->assertBusiness('gateway_failed', fn () => $service->resolve($dispute, $admin, DisputeStatus::ResolvedCustomer, 'refund', 100));

        $this->assertSame(DisputeStatus::Open, $dispute->fresh()->status);
        $this->assertNull($dispute->fresh()->resolved_at);
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    public function test_complaint_transitions(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $service = app(ComplaintService::class);
        $complaint = Complaint::create(['number' => 'CMP-T-1', 'user_id' => $customer->id, 'type' => 'complaint',
            'venue_id' => null, 'subject' => 'Table not ready', 'body' => '...', 'status' => ComplaintStatus::Open]);

        $service->assign($complaint, $admin, $admin);
        $this->assertSame(ComplaintStatus::InProgress, $complaint->fresh()->status);

        $service->reply($complaint, $admin, 'internal note', true);
        $this->assertSame(0, $customer->notifications()->count()); // internal notes are not sent
        $service->reply($complaint, $admin, 'We are on it');
        $this->assertSame(1, $customer->notifications()->where('data->key', 'complaint_replied')->count());

        $service->resolve($complaint, $admin, 'Refunded the difference');
        $this->assertSame(ComplaintStatus::Resolved, $complaint->fresh()->status);
        $this->assertNotNull($complaint->fresh()->resolved_at);
        $this->assertSame(1, $customer->notifications()->where('data->key', 'complaint_resolved')->count());

        // Customer reply re-opens a resolved ticket.
        $service->reply($complaint->fresh(), $customer, 'Still not received');
        $this->assertSame(ComplaintStatus::InProgress, $complaint->fresh()->status);
        $this->assertNull($complaint->fresh()->resolved_at);

        $service->close($complaint->fresh(), $admin);
        $this->assertBusiness('complaint_closed', fn () => $service->reply($complaint->fresh(), $customer, 'hello?'));
        $this->assertBusiness('invalid_transition', fn () => $service->changeStatus($complaint->fresh(), ComplaintStatus::Resolved, $admin));
        $this->assertBusiness('invalid_priority', fn () => $service->setPriority($complaint->fresh(), 'critical', $admin));
        $service->setPriority($complaint->fresh(), 'urgent', $admin);

        $this->assertGreaterThanOrEqual(4, AuditLog::where('auditable_type', 'complaint')->where('auditable_id', $complaint->id)->count());
    }

    public function test_review_moderation_updates_ratings_and_audits(): void
    {
        $order = $this->paidOrder($this->venue($this->provider()), 1);
        $venue = $order->venue;
        $review = Review::create(['user_id' => $order->user_id, 'venue_id' => $venue->id, 'provider_id' => $order->provider_id,
            'order_id' => $order->id, 'rating' => 1, 'comment' => 'spam', 'status' => 'published', 'provider_reply' => 'rude reply']);
        $this->assertSame(1, $venue->fresh()->rating_count);

        $admin = $this->admin();
        $service = app(ReviewModerationService::class);

        $service->hide($review, $admin, 'spam');
        $service->hide($review, $admin, 'spam'); // idempotent
        $this->assertSame('hidden', $review->fresh()->status);
        $this->assertSame(0, $venue->fresh()->rating_count);
        $this->assertSame(1, AuditLog::where('action', 'review.hidden')->count());

        $service->publish($review, $admin);
        $this->assertSame(1, $venue->fresh()->rating_count);

        $service->removeReply($review, $admin, 'abusive');
        $this->assertNull($review->fresh()->provider_reply);

        $this->assertSame(1, $service->hideMany([$review->fresh()], $admin));
        $this->assertBusiness('invalid_transition', fn () => $service->setStatus($review, 'deleted', $admin));
    }
}
