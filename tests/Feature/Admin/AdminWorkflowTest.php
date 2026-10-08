<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\ComplaintStatus;
use App\Enums\DisputeStatus;
use App\Enums\VenueStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\CommissionRules\Pages\ManageCommissionRules;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\Complaints\Pages\ViewComplaint;
use App\Filament\Resources\Complaints\RelationManagers\RepliesRelationManager;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Filament\Resources\HomeSections\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\BookingsRelationManager as OrderBookingsRelationManager;
use App\Filament\Resources\Providers\Pages\ViewProvider;
use App\Filament\Resources\Providers\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Providers\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Providers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Providers\RelationManagers\VenuesRelationManager;
use App\Filament\Resources\RecommendationRules\RecommendationRuleResource;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\Reviews\Pages\ManageReviews;
use App\Filament\Resources\Venues\Pages\ListVenues;
use App\Filament\Resources\Venues\Pages\ViewVenue;
use App\Filament\Resources\Venues\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Venues\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\Venues\RelationManagers\OrdersRelationManager as VenueOrdersRelationManager;
use App\Filament\Resources\Venues\VenueResource;
use App\Filament\Support\Fmt;
use App\Filament\Widgets\KpiStatsWidget;
use App\Filament\Widgets\LatestOrdersWidget;
use App\Filament\Widgets\OrdersBySectorChart;
use App\Filament\Widgets\SalesChart;
use App\Filament\Widgets\WorkQueueWidget;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CommissionRule;
use App\Models\Review;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\User;
use App\Services\KpiService;
use App\Services\OrderService;
use App\Services\ReportService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/**
 * Admin workflows on the venue-booking model: venue review, bookings, reports and the
 * actions that go through DisputeService / ComplaintService / ReviewModerationService.
 *
 * Run with: DB_DATABASE=farah_test_admin php artisan test tests/Feature/Admin
 */
class AdminWorkflowTest extends AdminTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->superAdmin(email: 'admin@farah.sa');
        $this->world();

        $this->actingAs($this->admin);
        $this->usePanel();
    }

    // ------------------------------------------------------------------ venues

    public function test_venue_review_goes_through_the_venue_service(): void
    {
        $venue = $this->pendingVenue;
        $this->assertSame('1', VenueResource::getNavigationBadge());

        Livewire::test(ViewVenue::class, ['record' => $venue->getRouteKey()])
            ->assertActionVisible('approve')
            ->assertActionHidden('suspend')
            ->callAction('reject', ['reason' => 'Photos are missing'])
            ->assertHasNoActionErrors();
        $venue->refresh();
        $this->assertSame(VenueStatus::Rejected, $venue->status);
        $this->assertSame('Photos are missing', $venue->rejection_reason);
        $this->assertSame($this->admin->id, $venue->reviewed_by);

        $venue->update(['status' => VenueStatus::PendingReview]);
        Livewire::test(ViewVenue::class, ['record' => $venue->getRouteKey()])->callAction('approve')->assertNotified();
        $this->assertSame(VenueStatus::Published, $venue->fresh()->status);
        $this->assertNotNull($venue->fresh()->published_at);

        Livewire::test(ViewVenue::class, ['record' => $venue->getRouteKey()])
            ->assertActionHidden('approve')
            ->callAction('suspend', ['reason' => 'Licence expired'])
            ->assertHasNoActionErrors();
        $this->assertSame(VenueStatus::Suspended, $venue->fresh()->status);

        Livewire::test(ViewVenue::class, ['record' => $venue->getRouteKey()])->callAction('reactivate')->assertNotified();
        $this->assertSame(VenueStatus::Published, $venue->fresh()->status);

        Livewire::test(ViewVenue::class, ['record' => $venue->getRouteKey()])->callAction('toggle_featured')->assertNotified();
        $this->assertTrue($venue->fresh()->is_featured);
    }

    public function test_venue_list_tabs_filters_and_view_page(): void
    {
        Livewire::test(ListVenues::class)
            ->assertCanSeeTableRecords([$this->venue, $this->pendingVenue])
            ->filterTable('status', VenueStatus::PendingReview->value)
            ->assertCanSeeTableRecords([$this->pendingVenue])
            ->assertCanNotSeeTableRecords([$this->venue])
            ->resetTableFilters()
            ->filterTable('sector_id', $this->sector->id)
            ->assertCanSeeTableRecords([$this->venue]);

        Livewire::test(ListVenues::class, ['activeTab' => VenueStatus::Published->value])
            ->assertCanSeeTableRecords([$this->venue])
            ->assertCanNotSeeTableRecords([$this->pendingVenue]);

        // Admins review venues; they do not create or edit them.
        $this->assertFalse(VenueResource::canCreate());
        $this->assertArrayNotHasKey('create', VenueResource::getPages());

        // Working hours (10:00 → 00:00 every day) and the activity type are on the view page.
        $this->withSession(['admin_locale' => 'en']);
        $this->get(VenueResource::getUrl('view', ['record' => $this->venue]))
            ->assertOk()
            ->assertSee('Cue Club Olaya')
            ->assertSee('Sunday')
            ->assertSee('10:00 → 00:00')
            ->assertSee($this->sector->name_en)
            ->assertDontSee('admin.venues.')
            ->assertDontSee('admin.fields.');
    }

    public function test_venue_relation_managers_load(): void
    {
        $activity = $this->activity($this->venue);

        Livewire::test(ActivitiesRelationManager::class, ['ownerRecord' => $this->venue, 'pageClass' => ViewVenue::class])
            ->assertOk()
            ->assertCanSeeTableRecords([$activity])
            ->assertSee($activity->resources->first()->name_ar);

        Livewire::test(BookingsRelationManager::class, ['ownerRecord' => $this->venue, 'pageClass' => ViewVenue::class])
            ->assertOk()
            ->assertCanSeeTableRecords($this->venue->bookings()->get())
            ->assertCountTableRecords(3);

        Livewire::test(VenueOrdersRelationManager::class, ['ownerRecord' => $this->venue, 'pageClass' => ViewVenue::class])
            ->assertOk()
            ->assertCanSeeTableRecords([$this->order, $this->splitOrder]);
    }

    // ------------------------------------------------------------------ bookings

    public function test_bookings_list_is_read_only_and_filters_by_status_venue_and_date(): void
    {
        $booking = $this->order->bookings->first();
        $other = $this->venue($this->provider(name: 'Lanes'), ['name_en' => 'Bowling place']);
        $otherBooking = $this->paidOrder($other, 1)->bookings->first();
        app(OrderService::class)->releaseBookings([$otherBooking], BookingStatus::Cancelled);

        $this->assertFalse(BookingResource::canCreate());
        $this->assertFalse(BookingResource::canEdit($booking));
        $this->assertFalse(BookingResource::canDelete($booking));

        // Time windows are shown on the venue's clock (Asia/Riyadh), not in UTC.
        $riyadh = $booking->starts_at->timezone('Asia/Riyadh');
        $this->assertSame('20:00', $riyadh->format('H:i'));
        $this->assertSame($riyadh->format('Y-m-d').' 20:00 → 22:00', BookingResource::window($booking));

        Livewire::test(ListBookings::class)
            ->assertCountTableRecords(4)
            ->assertSee($booking->code)
            ->assertSee($this->order->number)
            ->filterTable('venue_id', $this->venue->id)
            ->assertCountTableRecords(3)
            ->assertCanNotSeeTableRecords([$otherBooking])
            ->resetTableFilters()
            ->filterTable('status', [BookingStatus::Cancelled->value])
            ->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([$otherBooking])
            ->resetTableFilters()
            // slot(3) is the booking day of $this->order; the split order plays on slot(4).
            ->filterTable('played_between', ['from' => $riyadh->format('Y-m-d'), 'until' => $riyadh->format('Y-m-d')])
            ->assertCanSeeTableRecords([$booking])
            ->assertCanNotSeeTableRecords($this->splitOrder->bookings);

        $this->withSession(['admin_locale' => 'en']);
        $this->get(BookingResource::getUrl('view', ['record' => $booking]))
            ->assertOk()
            ->assertSee($booking->code)
            ->assertSee('20:00 → 22:00')
            ->assertSee('Cue Club Olaya')
            ->assertSee($this->order->number)
            ->assertDontSee('admin.bookings.');
    }

    public function test_order_page_lists_its_bookings(): void
    {
        Livewire::test(OrderBookingsRelationManager::class, ['ownerRecord' => $this->splitOrder, 'pageClass' => ViewOrder::class])
            ->assertOk()
            ->assertCountTableRecords(2)
            ->assertCanSeeTableRecords($this->splitOrder->bookings);

        $this->get(OrderResource::getUrl('view', ['record' => $this->order]))
            ->assertOk()
            ->assertSee($this->venue->name_ar);
    }

    public function test_refund_shows_the_bookings_it_covers(): void
    {
        $booking = $this->splitOrder->bookings->first();

        $this->assertSame('partial', $this->refund->type);
        $this->assertSame($booking->name.' ('.$booking->code.')', RefundResource::itemsSummary($this->refund->fresh()));
        $this->get(RefundResource::getUrl('view', ['record' => $this->refund]))->assertOk()->assertSee($booking->code);

        Livewire::test(ListRefunds::class)
            ->callTableAction('approve', $this->refund)
            ->assertHasNoTableActionErrors();
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $this->splitOrder->bookings->last()->fresh()->status);
    }

    // ------------------------------------------------------------------ providers / content

    /** The provider relation managers used `fn ($q)` in modifyQueryUsing, which Filament resolves by name (null) → 500. */
    public function test_provider_relation_managers_load(): void
    {
        foreach ([DocumentsRelationManager::class, VenuesRelationManager::class, MembersRelationManager::class, OrdersRelationManager::class] as $rm) {
            Livewire::test($rm, ['ownerRecord' => $this->provider, 'pageClass' => ViewProvider::class])
                ->assertOk()
                ->assertCanSeeTableRecords($this->provider->{match ($rm) {
                    DocumentsRelationManager::class => 'documents',
                    VenuesRelationManager::class => 'venues',
                    MembersRelationManager::class => 'members',
                    OrdersRelationManager::class => 'orders',
                }}()->limit(3)->get());
        }
    }

    public function test_content_links_and_strategies_use_venues(): void
    {
        $this->assertArrayHasKey('venue', ItemsRelationManager::linkTypes());
        $this->assertArrayNotHasKey('event', ItemsRelationManager::linkTypes());
        $this->assertSame($this->venue->name_ar, ItemsRelationManager::linkOptions('venue')[$this->venue->id]);
        $this->assertArrayHasKey($this->pendingVenue->id, ItemsRelationManager::linkOptions('venue'));

        $strategies = RecommendationRuleResource::strategies();
        $this->assertArrayHasKey('latest', $strategies);
        $this->assertArrayNotHasKey('upcoming', $strategies);
        foreach ($strategies as $key => $label) {
            $this->assertStringNotContainsString('admin.strategies', $label, $key);
        }
    }

    public function test_settings_page_saves_the_booking_limit(): void
    {
        Livewire::test(Settings::class)
            ->assertOk()
            ->assertFormFieldExists('max_bookings_per_order')
            ->fillForm(['max_bookings_per_order' => 6])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(6, (int) Setting::get('max_bookings_per_order'));
    }

    // ------------------------------------------------------------------ locale / search / dashboard

    public function test_english_mode_shows_english_names_and_translated_values(): void
    {
        $provider = $this->provider;
        $this->withSession(['admin_locale' => 'en']);

        $this->get('/admin/providers')->assertOk()->assertSee($provider->name_en);
        $this->get('/admin/audit-logs')->assertOk()->assertDontSee('admin.subjects.');

        app()->setLocale('en');
        $this->assertSame('Apple Pay', Fmt::label('payment_methods', 'applepay'));
        $this->assertSame('unknown_value', Fmt::label('payment_methods', 'unknown_value'));
        $this->assertSame($provider->name_en, Fmt::localized('name')($provider->name_ar, $provider));
        app()->setLocale('ar');
        $this->assertSame($provider->name_ar, Fmt::localized('name')($provider->name_ar, $provider));
        $this->assertStringStartsWith("\u{200E}", Fmt::phone('+966500000000'));
    }

    /** Navigation groups used to be translated once at boot (always Arabic); record titles used the *_ar column. */
    public function test_navigation_groups_and_record_titles_follow_the_locale(): void
    {
        $this->withSession(['admin_locale' => 'en']);

        $this->get('/admin')->assertOk()
            ->assertSee(trans('admin.nav.approvals', [], 'en'))
            ->assertSee(trans('admin.nav.catalog', [], 'en'))
            ->assertDontSee(trans('admin.nav.approvals', [], 'ar'));
        $this->get(VenueResource::getUrl('view', ['record' => $this->venue]))
            ->assertOk()
            ->assertSee(e($this->venue->name_en), false);

        $this->withSession(['admin_locale' => 'ar']);
        $this->get('/admin')->assertOk()->assertSee(trans('admin.nav.approvals', [], 'ar'));
    }

    public function test_global_search_finds_orders_providers_users_venues_and_bookings(): void
    {
        $booking = $this->order->bookings->first();
        $search = Filament::getGlobalSearchProvider();

        $titles = fn (string $q) => collect($search->getResults($q)?->getCategories() ?? [])->flatten(1)->map(fn ($r) => (string) $r->title)->all();

        $this->assertContains($this->order->number, $titles($this->order->number));
        $this->assertContains($this->provider->name_ar, $titles($this->provider->name_ar));
        $this->assertContains($this->buyer->name, $titles($this->buyer->phone));
        $this->assertContains($this->venue->name_ar, $titles($this->venue->name_ar));
        $this->assertContains($booking->code, $titles($booking->code));
    }

    public function test_navigation_badges_share_one_queue_computation(): void
    {
        $queue = app(KpiService::class)->queue();

        $this->assertSame(1, $queue['venues']);
        $this->assertArrayNotHasKey('events', $queue);
        $this->assertSame($queue['disputes'] ? (string) $queue['disputes'] : null, DisputeResource::getNavigationBadge());
        $this->get('/admin')->assertOk();
    }

    public function test_dashboard_widgets_follow_the_date_range(): void
    {
        $this->get(Dashboard::getUrl())->assertOk();

        Livewire::test(KpiStatsWidget::class, ['pageFilters' => ['from' => now()->subYears(5)->toDateString(), 'to' => now()->toDateString()]])
            ->assertOk()
            ->assertSee(trans('admin.kpi.live_venues'))
            ->assertSee(trans('admin.kpi.bookings_today'));
        Livewire::test(SalesChart::class, ['pageFilters' => ['from' => now()->subDays(6)->toDateString(), 'to' => now()->toDateString()]])
            ->assertOk()
            ->assertSee('#B21FD1');
        Livewire::test(OrdersBySectorChart::class)->assertOk();
        Livewire::test(WorkQueueWidget::class)->assertOk()->assertSee(trans('admin.queue.venues'))->assertSee('admin/venues');
        Livewire::test(LatestOrdersWidget::class)->assertOk()->assertSee($this->order->number)->assertSee($this->venue->name_ar);

        [$from, $to] = KpiService::range();
        $kpi = app(KpiService::class)->summary($from, $to);
        $this->assertSame(2, $kpi['orders']);
        $this->assertSame(400.0, $kpi['gmv']);
        $this->assertSame(1, $kpi['live_venues']); // the pending venue is not live
        $this->assertSame(0, $kpi['bookings_today']);

        // A booking that plays today (Riyadh) counts, whatever the order date.
        Booking::whereKey($this->order->bookings->first()->id)->update(['starts_at' => now('Asia/Riyadh')->setTime(12, 0)->utc()]);
        $this->assertSame(1, app(KpiService::class)->bookingsToday());

        $bySector = app(KpiService::class)->ordersBySector($from, $to);
        $this->assertSame([$this->sector->name_ar], $bySector['labels']);
        $this->assertSame([3], $bySector['bookings']);
        $this->assertSame([400.0], $bySector['gmv']);

        [$from, $to] = KpiService::range(now()->addYear()->toDateString(), now()->addYear()->addDay()->toDateString());
        $this->assertSame(0, app(KpiService::class)->summary($from, $to)['orders']);
    }

    // ------------------------------------------------------------------ reports

    public function test_reports_are_built_from_venues_and_bookings(): void
    {
        $reports = app(ReportService::class);
        $this->assertContains('sales_by_venue', ReportService::REPORTS);
        $this->assertContains('venue_occupancy', ReportService::REPORTS);
        $this->assertNotContains('sales_by_event', ReportService::REPORTS);

        $byVenue = $reports->run('sales_by_venue');
        $this->assertCount(1, $byVenue['rows']);
        $row = $byVenue['rows'][0];
        $this->assertSame($this->venue->name_ar, $row['venue']);
        $this->assertSame(2, $row['orders']);
        $this->assertSame(3, $row['bookings']);
        $this->assertSame(4.0, $row['hours']);
        $this->assertSame(400.0, $row['gross']);
        $this->assertSame(40.0, $row['commission']); // 10% global commission
        $this->assertArrayNotHasKey('tickets', $row);

        $bySector = $reports->run('sales_by_sector');
        $this->assertSame($this->sector->name_ar, $bySector['rows'][0]['sector']);
        $this->assertSame(3, $bySector['rows'][0]['bookings']);
        $this->assertSame(400.0, $bySector['rows'][0]['gross']);
        $this->assertSame(100.0, $bySector['rows'][0]['avg_hour']);

        // Filters: another activity type matches nothing; the venue's own type matches everything.
        $otherSector = Sector::where('id', '!=', $this->sector->id)->firstOrFail();
        $this->assertSame([], $reports->run('sales_by_venue', ['sector_id' => $otherSector->id])['rows']);
        $this->assertCount(1, $reports->run('sales_by_venue', ['sector_id' => $this->sector->id, 'venue_id' => $this->venue->id])['rows']);
        $this->assertSame([], $reports->run('sales_by_period', ['venue_id' => $this->pendingVenue->id])['rows']);

        // Occupancy for the coming week: open 14 h/day (10:00 → 00:00) x 3 tables x 7 days = 294 h, 4 h booked.
        $occupancy = $reports->run('venue_occupancy', ['from' => now('Asia/Riyadh')->toDateString(), 'to' => now('Asia/Riyadh')->addDays(6)->toDateString(), 'venue_id' => $this->venue->id]);
        $row = $occupancy['rows'][0];
        $this->assertSame(3, $row['resources']);
        $this->assertSame(3, $row['bookings']);
        $this->assertSame(4.0, $row['hours']);
        $this->assertSame(294.0, $row['available']);
        $this->assertSame('1.4%', $row['occupancy']);
        $this->assertSame(400.0, $row['gross']);

        $providers = $reports->run('providers', ['provider_id' => $this->provider->id])['rows'][0];
        $this->assertSame(2, $providers['venues']);
        $this->assertSame(1, $providers['published']);

        $this->assertSame(3, $reports->run('reviews_complaints', ['sector_id' => $this->sector->id])['rows'][0]['reviews']);
        $this->assertSame($this->venue->name_ar, $reports->run('refunds_disputes')['rows'][0]['venue']);

        foreach (ReportService::REPORTS as $key) {
            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $result = $reports->run($key, ['sector_id' => $this->sector->id]);
                foreach ($result['columns'] as $label) {
                    $this->assertStringNotContainsString('admin.reports', $label, "{$key} ({$locale})");
                }
                $this->assertStringNotContainsString('admin.reports', $reports->title($key));
            }
        }
        app()->setLocale('ar');
    }

    // ------------------------------------------------------------------ support workflows

    public function test_dispute_workflow_goes_through_the_service(): void
    {
        $dispute = $this->dispute;

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->callAction('start_review')
            ->assertNotified();
        $this->assertSame(DisputeStatus::UnderReview, $dispute->fresh()->status);

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->callAction('resolve', ['outcome' => DisputeStatus::ResolvedProvider->value, 'resolution' => 'The table was available on time.'])
            ->assertHasNoActionErrors();
        $dispute->refresh();
        $this->assertSame(DisputeStatus::ResolvedProvider, $dispute->status);
        $this->assertSame($this->admin->id, $dispute->resolved_by);
        $this->assertTrue(AuditLog::where('auditable_type', $dispute->getMorphClass())->where('auditable_id', $dispute->id)->exists());

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->callAction('reopen', ['reason' => 'New evidence'])
            ->assertHasNoActionErrors();
        $this->assertSame(DisputeStatus::UnderReview, $dispute->fresh()->status);
    }

    public function test_complaint_workflow_goes_through_the_service(): void
    {
        $complaint = $this->complaint;

        $this->get(ComplaintResource::getUrl('view', ['record' => $complaint]))
            ->assertOk()->assertSee($this->venue->name_ar);

        Livewire::test(ViewComplaint::class, ['record' => $complaint->getRouteKey()])
            ->callAction('assign', ['assigned_to' => $this->admin->id])
            ->assertHasNoActionErrors();
        $this->assertSame(ComplaintStatus::InProgress, $complaint->fresh()->status);

        Livewire::test(RepliesRelationManager::class, ['ownerRecord' => $complaint->fresh(), 'pageClass' => ViewComplaint::class])
            ->callTableAction('reply', data: ['body' => 'We are on it.', 'is_internal' => false])
            ->assertHasNoTableActionErrors();
        $this->assertTrue($complaint->replies()->where('body', 'We are on it.')->exists());

        Livewire::test(ViewComplaint::class, ['record' => $complaint->getRouteKey()])
            ->callAction('resolve', ['resolution' => 'One hour was added to the booking.'])
            ->assertHasNoActionErrors();
        $this->assertSame(ComplaintStatus::Resolved, $complaint->fresh()->status);

        Livewire::test(ViewComplaint::class, ['record' => $complaint->getRouteKey()])
            ->callAction('close')
            ->assertHasNoActionErrors();
        $this->assertSame(ComplaintStatus::Closed, $complaint->fresh()->status);
    }

    public function test_review_moderation_goes_through_the_service(): void
    {
        $review = Review::where('status', 'published')->firstOrFail();

        Livewire::test(ManageReviews::class)
            ->assertSee($this->venue->name_ar)
            ->callTableAction('hide', $review, ['reason' => 'Offensive'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('hidden', $review->fresh()->status);
        $this->assertTrue(AuditLog::where('auditable_id', $review->id)->where('auditable_type', $review->getMorphClass())->exists());

        Livewire::test(ManageReviews::class)
            ->callTableAction('publish', $review)
            ->assertHasNoTableActionErrors();
        $this->assertSame('published', $review->fresh()->status);

        $others = Review::where('status', 'published')->limit(2)->get();
        Livewire::test(ManageReviews::class)
            ->callTableBulkAction('hide_selected', $others, ['reason' => 'Spam'])
            ->assertHasNoTableBulkActionErrors();
        $this->assertSame(0, Review::whereKey($others->modelKeys())->where('status', 'published')->count());
    }

    public function test_commission_new_version_must_start_after_the_current_version(): void
    {
        $rule = CommissionRule::whereNull('effective_to')->where('is_active', true)->firstOrFail();
        $rule->update(['effective_from' => now()->addDays(2)]);

        Livewire::test(ManageCommissionRules::class)
            ->callTableAction('new_version', $rule, ['type' => 'percentage', 'rate' => 11, 'fixed_amount' => 0, 'effective_from' => now()->toDateTimeString()])
            ->assertHasTableActionErrors(['effective_from']);
        $this->assertNull($rule->fresh()->effective_to);

        Livewire::test(ManageCommissionRules::class)
            ->callTableAction('new_version', $rule, ['type' => 'percentage', 'rate' => 11, 'fixed_amount' => 0, 'effective_from' => now()->addDays(3)->toDateTimeString()])
            ->assertHasNoTableActionErrors();
        $this->assertNotNull($rule->fresh()->effective_to);
    }

    // ------------------------------------------------------------------ permissions

    public function test_limited_roles_match_the_permission_config(): void
    {
        $this->assertContains('venues', config('farah.admin_modules'));
        $this->assertNotContains('events', config('farah.admin_modules'));
        $this->assertTrue(Permission::where('name', 'venues.manage')->exists());
        $this->assertFalse(Permission::where('name', 'like', 'events.%')->exists());

        $venue = VenueResource::getUrl('view', ['record' => $this->pendingVenue], isAbsolute: false);

        $expect = [
            'operations' => ['/admin/providers' => 200, '/admin/venues' => 200, $venue => 200, '/admin/users' => 200, '/admin/orders' => 403, '/admin/bookings' => 403, '/admin/settlements' => 403, '/admin/reports' => 403],
            'finance' => ['/admin/orders' => 200, '/admin/bookings' => 200, '/admin/settlements' => 200, '/admin/reports' => 200, '/admin/providers' => 200, '/admin/venues' => 403, '/admin/users' => 403, '/admin/home-sections' => 403],
            'content' => ['/admin/home-sections' => 200, '/admin/static-pages' => 200, '/admin/push-campaigns' => 200, '/admin/venues' => 200, '/admin/orders' => 403],
            'support' => ['/admin/complaints' => 200, '/admin/disputes' => 200, '/admin/orders' => 200, '/admin/bookings' => 200, '/admin/venues' => 200, '/admin/refunds' => 200, '/admin/settlements' => 403, '/admin/settings' => 403],
        ];

        foreach ($expect as $role => $urls) {
            // AuthenticateSession pins the session to one user; start clean for each role.
            $this->flushSession();
            auth()->guard('web')->logout();
            $this->actingAs($this->superAdmin($role));
            foreach ($urls as $url => $status) {
                $this->assertSame($status, $this->get($url)->status(), "{$role} {$url}");
            }
        }

        // "venues.view" (content, support) can read a venue but not review it; operations can.
        $this->usePanel();
        Livewire::test(ViewVenue::class, ['record' => $this->pendingVenue->getRouteKey()])
            ->assertActionHidden('approve')
            ->assertActionHidden('toggle_featured');

        $this->flushSession();
        auth()->guard('web')->logout();
        $this->actingAs(User::where('email', 'operations@farah.test')->firstOrFail());
        Livewire::test(ViewVenue::class, ['record' => $this->pendingVenue->getRouteKey()])->assertActionVisible('approve');
    }
}
