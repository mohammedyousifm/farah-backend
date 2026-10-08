<?php

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Enums\VenueStatus;
use App\Models\Complaint;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Provider;
use App\Models\Refund;
use App\Models\Review;
use App\Models\User;
use App\Models\Venue;
use App\Services\DisputeService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RefundService;
use Filament\Facades\Filament;
use Tests\Feature\Services\ServiceTestCase;

/**
 * Shared fixtures for the admin panel tests: the API / service fixtures (reference data,
 * approved provider, published venue with 3 billiards tables at 100 SAR / hour) plus a small
 * "world" with one record for every admin resource.
 *
 * Run with: DB_DATABASE=farah_test_admin php artisan test tests/Feature/Admin
 */
abstract class AdminTestCase extends ServiceTestCase
{
    protected Provider $provider;

    protected Venue $venue;

    protected Venue $pendingVenue;

    protected User $buyer;

    /** Paid order: one table for two hours (200 SAR). */
    protected Order $order;

    /** Paid order with two bookings (two tables, one hour each, 200 SAR) and a requested partial refund. */
    protected Order $splitOrder;

    protected Refund $refund;

    protected Dispute $dispute;

    protected Complaint $complaint;

    protected function superAdmin(string $role = 'super_admin', ?string $email = null): User
    {
        $user = User::create([
            'name' => 'Admin '.$role, 'email' => $email ?? $role.'@farah.test', 'password' => 'password',
            'type' => UserType::Admin, 'status' => UserStatus::Active,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function usePanel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** One record (at least) for every admin resource, built through the real services. */
    protected function world(): void
    {
        $this->provider = $this->provider(name: 'Cue Club');
        $this->provider->documents()->create(['type' => 'commercial_register', 'title' => 'CR', 'file_path' => 'documents/cr.pdf', 'status' => 'pending']);

        $this->venue = $this->venue($this->provider, ['name_ar' => 'نادي الكيو', 'name_en' => 'Cue Club Olaya']);
        $this->pendingVenue = $this->venue($this->provider, [
            'name_ar' => 'فرع جديد', 'name_en' => 'New branch', 'status' => VenueStatus::PendingReview, 'published_at' => null, 'submitted_at' => now(),
        ]);

        $this->buyer = $this->customer('+966502222222', ['name' => 'Sara Buyer']);
        $this->order = $this->paidOrder($this->venue, 2, $this->buyer);

        $at = $this->slot(4, '18:00');
        $split = app(OrderService::class)->createOnline($this->buyer, [$this->line($this->venue, 1, $at), $this->line($this->venue, 1, $at)]);
        app(PaymentService::class)->markPaid(app(PaymentService::class)->checkout($this->buyer, collect([$split])), 'pay_split', 'mada');
        $this->splitOrder = $split->fresh(['bookings', 'venue']);

        $this->refund = app(RefundService::class)->request($this->splitOrder, $this->buyer, [['booking_id' => $this->splitOrder->bookings->first()->id]], 'Changed plans');
        $this->dispute = app(DisputeService::class)->open($this->order, $this->buyer, 'Table was not available', null, 100);

        $this->complaint = Complaint::create([
            'number' => 'CMP-TEST-1', 'user_id' => $this->buyer->id, 'provider_id' => $this->provider->id, 'order_id' => $this->order->id,
            'venue_id' => $this->venue->id, 'subject' => 'Late start', 'body' => 'The table was handed over late.',
        ]);

        foreach ([5, 4, 2] as $i => $rating) {
            Review::create([
                'user_id' => $i === 0 ? $this->buyer->id : $this->customer('+96650333333'.$i)->id,
                'venue_id' => $this->venue->id, 'provider_id' => $this->provider->id, 'order_id' => $i === 0 ? $this->order->id : null,
                'rating' => $rating, 'comment' => 'Review '.$rating, 'status' => 'published',
            ]);
        }
    }
}
