<?php

namespace Tests\Feature\Provider;

use App\Enums\ProviderStatus;
use App\Enums\UserType;
use App\Filament\Provider\Pages\Auth\RegisterProvider;
use App\Filament\Provider\Pages\CheckIn;
use App\Filament\Provider\Pages\Dashboard;
use App\Filament\Provider\Pages\EditProviderProfile;
use App\Filament\Provider\Pages\Schedule;
use App\Filament\Provider\Pages\Sectors;
use App\Filament\Provider\Resources\Bookings\BookingResource;
use App\Filament\Provider\Resources\Documents\DocumentResource;
use App\Filament\Provider\Resources\Documents\Pages\ManageDocuments;
use App\Filament\Provider\Resources\Ledger\LedgerEntryResource;
use App\Filament\Provider\Resources\Orders\OrderResource;
use App\Filament\Provider\Resources\Orders\Pages\ViewOrder;
use App\Filament\Provider\Resources\Orders\RelationManagers\BookingsRelationManager;
use App\Filament\Provider\Resources\Orders\RelationManagers\RefundsRelationManager;
use App\Filament\Provider\Resources\Reviews\Pages\ManageReviews;
use App\Filament\Provider\Resources\Reviews\ReviewResource;
use App\Filament\Provider\Resources\SectorRequests\Pages\ManageSectorRequests;
use App\Filament\Provider\Resources\SectorRequests\SectorRequestResource;
use App\Filament\Provider\Resources\Settlements\SettlementResource;
use App\Filament\Provider\Resources\Team\MemberResource;
use App\Filament\Provider\Resources\Team\Pages\ManageMembers;
use App\Filament\Provider\Resources\Venues\Pages\ViewVenue;
use App\Filament\Provider\Resources\Venues\RelationManagers\ActivitiesRelationManager;
use App\Filament\Provider\Resources\Venues\RelationManagers\MediaRelationManager;
use App\Filament\Provider\Resources\Venues\RelationManagers\ResourcesRelationManager;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Provider\Widgets\BalancesWidget;
use App\Filament\Provider\Widgets\LatestOrdersWidget;
use App\Filament\Provider\Widgets\OnboardingWidget;
use App\Filament\Provider\Widgets\ProviderStatsWidget;
use App\Filament\Provider\Widgets\UpcomingBookingsWidget;
use App\Models\City;
use App\Models\Provider;
use App\Models\ProviderMember;
use App\Models\Review;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\ProviderService;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Provider web dashboard (/provider): every page renders in both languages, tenancy,
 * member permissions, onboarding / suspended restrictions and the account workflows.
 */
class ProviderPanelTest extends ProviderPanelTestCase
{
    /** @return array<string, string> name => url */
    private function pageUrls(Provider $tenant): array
    {
        Filament::setCurrentPanel(Filament::getPanel('provider'));
        $venue = $tenant->venues()->first();
        $order = $tenant->orders()->first();
        $t = ['tenant' => $tenant];

        $urls = [
            'dashboard' => Dashboard::getUrl(...$t),
            'profile' => EditProviderProfile::getUrl(...$t),
            'sectors' => Sectors::getUrl(...$t),
            'check-in' => CheckIn::getUrl(...$t),
            'schedule' => Schedule::getUrl(...$t),
            'documents' => DocumentResource::getUrl(...$t),
            'sector-requests' => SectorRequestResource::getUrl(...$t),
            'venues' => VenueResource::getUrl(...$t),
            'venues.create' => VenueResource::getUrl('create', ...$t),
            'bookings' => BookingResource::getUrl(...$t),
            'orders' => OrderResource::getUrl(...$t),
            'team' => MemberResource::getUrl(...$t),
            'ledger' => LedgerEntryResource::getUrl(...$t),
            'settlements' => SettlementResource::getUrl(...$t),
            'reviews' => ReviewResource::getUrl(...$t),
        ];
        if ($venue) {
            $urls['venues.view'] = VenueResource::getUrl('view', ['record' => $venue], ...$t);
            $urls['venues.edit'] = VenueResource::getUrl('edit', ['record' => $venue], ...$t);
        }
        if ($order) {
            $urls['orders.view'] = OrderResource::getUrl('view', ['record' => $order], ...$t);
        }

        return $urls;
    }

    public function test_owner_can_open_every_page_in_both_languages(): void
    {
        $this->booking();
        $urls = $this->pageUrls($this->provider);
        $this->assertArrayHasKey('orders.view', $urls);

        $this->actingAs($this->owner, 'provider');
        // One venue per provider: with a venue already there, "create" is closed.
        $this->assertStatuses(['venues.create' => $urls['venues.create']], 403);
        unset($urls['venues.create']);
        $this->assertStatuses($urls, 200);

        $this->withSession(['admin_locale' => 'en']);
        $this->assertStatuses($urls, 200);
        foreach ($urls as $url) {
            $html = (string) $this->get($url)->getContent();
            $this->assertDoesNotMatchRegularExpression('/provider\.(fields|resources|nav|dashboard|balances|venues|schedule|bookings|orders|team|permissions|checkin|onboarding|banner|profile|sectors|sector_requests|documents|settlements|finance|tabs|days|reviews|register|actions)\.[a-z_]+/', $html, "Untranslated key on {$url}");
            $this->assertDoesNotMatchRegularExpression('/(enums|api)\.[a-z_]+\.[a-z_]+/', strip_tags($html), "Untranslated key on {$url}");
        }
    }

    /** Widgets and relation managers load lazily, so render them directly. */
    public function test_widgets_and_relation_managers_render(): void
    {
        $order = $this->booking();
        $this->venue->media()->create(['type' => 'video', 'url' => 'https://example.com/v.mp4', 'sort_order' => 1]);
        $this->asOwner();

        foreach ([ProviderStatsWidget::class, UpcomingBookingsWidget::class, LatestOrdersWidget::class, BalancesWidget::class, OnboardingWidget::class] as $widget) {
            Livewire::test($widget)->assertOk();
        }
        Livewire::test(UpcomingBookingsWidget::class)->assertCanSeeTableRecords($order->bookings);

        $activity = $this->activity($this->venue);
        Livewire::test(ActivitiesRelationManager::class, ['ownerRecord' => $this->venue, 'pageClass' => ViewVenue::class])
            ->assertOk()->assertCanSeeTableRecords([$activity]);
        Livewire::test(ResourcesRelationManager::class, ['ownerRecord' => $this->venue, 'pageClass' => ViewVenue::class])
            ->assertOk()->assertCanSeeTableRecords($activity->resources);
        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $this->venue, 'pageClass' => ViewVenue::class])
            ->assertOk()->assertCanSeeTableRecords($this->venue->media);

        Livewire::test(BookingsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertOk()->assertCanSeeTableRecords($order->bookings);
        Livewire::test(RefundsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])->assertOk();
    }

    public function test_root_redirects_to_the_default_tenant(): void
    {
        $this->actingAs($this->owner, 'provider')->get('/provider')
            ->assertRedirect(Dashboard::getUrl(tenant: $this->provider));
    }

    public function test_member_permissions_decide_which_modules_open(): void
    {
        $this->booking();
        $urls = $this->pageUrls($this->provider);
        $only = fn (array $names) => array_intersect_key($urls, array_flip($names));

        // Sees orders and bookings, nothing else.
        $this->actingAs($this->staff(['orders.view']), 'provider');
        $this->assertStatuses($only(['dashboard', 'venues', 'venues.view', 'orders', 'orders.view', 'bookings', 'schedule', 'reviews', 'sectors']), 200);
        $this->assertStatuses($only(['profile', 'documents', 'check-in', 'team', 'ledger', 'settlements', 'venues.create', 'venues.edit']), 403);

        // Counter staff: check-in only.
        $this->flushSession(); // AuthenticateSession ties the session to one user's password hash
        $this->actingAs($this->staff(['bookings.checkin'], 'door@example.com'), 'provider');
        $this->assertStatuses($only(['dashboard', 'check-in', 'venues']), 200);
        $this->assertStatuses($only(['orders', 'bookings', 'schedule', 'venues.create', 'venues.edit', 'ledger']), 403);

        // Venue manager: venues and the schedule, no orders.
        $this->flushSession();
        $this->actingAs($this->staff(['venues.manage'], 'venues@example.com'), 'provider');
        $this->assertStatuses($only(['venues', 'venues.edit', 'schedule']), 200);
        $this->assertStatuses($only(['orders', 'bookings', 'check-in', 'venues.create']), 403); // create: the provider already has its venue
    }

    public function test_deactivated_member_loses_access(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => 'password', 'type' => UserType::Customer, 'status' => 'active']);
        $this->provider->members()->create(['user_id' => $staff->id, 'role' => 'manager', 'is_active' => false]);

        $this->actingAs($staff, 'provider')->get('/provider')->assertForbidden();
    }

    public function test_user_cannot_reach_another_providers_tenant_or_records(): void
    {
        $other = $this->provider(null, 'Other');
        $foreignVenue = $this->venue($other);
        $foreignOrder = $this->booking($foreignVenue);
        $otherUrls = $this->pageUrls($other);

        $this->actingAs($this->owner, 'provider');
        $this->assertStatuses(array_intersect_key($otherUrls, array_flip(['dashboard', 'venues', 'bookings', 'schedule', 'orders', 'ledger'])), 404);

        // Another provider's record under my own tenant URL.
        $this->get(VenueResource::getUrl('view', ['record' => $foreignVenue], tenant: $this->provider))->assertNotFound();
        $this->get(VenueResource::getUrl('edit', ['record' => $foreignVenue], tenant: $this->provider))->assertNotFound();
        $this->get(OrderResource::getUrl('view', ['record' => $foreignOrder], tenant: $this->provider))->assertNotFound();

        // Lists and the schedule never show the other provider's data.
        $this->get(BookingResource::getUrl(tenant: $this->provider))->assertOk()->assertDontSee($foreignOrder->bookings->first()->code);
        $this->get(Schedule::getUrl(['venueId' => $foreignVenue->id], tenant: $this->provider))->assertOk()
            ->assertDontSee($foreignOrder->bookings->first()->code);
    }

    public function test_admin_and_customer_cannot_use_the_provider_panel(): void
    {
        $customer = $this->customer('+966502222222', ['email' => 'customer0@example.com', 'password' => 'password']);

        $this->actingAs($this->admin, 'provider')->get('/provider')->assertForbidden();
        $this->actingAs($customer, 'provider')->get('/provider')->assertForbidden();
        auth('provider')->logout();

        Filament::setCurrentPanel(Filament::getPanel('provider'));
        foreach (['admin@farah.sa', 'customer0@example.com'] as $email) {
            Livewire::test(Login::class)
                ->fillForm(['email' => $email, 'password' => 'password'])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);
            $this->assertGuest('provider');
        }

        Livewire::test(Login::class)
            ->fillForm(['email' => 'owner@example.com', 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->owner, 'provider');
    }

    public function test_pending_provider_only_reaches_onboarding_pages(): void
    {
        $this->provider->update(['status' => ProviderStatus::Pending, 'iban' => null, 'bank_name' => null]);
        $urls = $this->pageUrls($this->provider->fresh());

        $this->actingAs($this->owner, 'provider');
        // The store can be prepared before approval (one request); day-to-day pages open after it.
        $this->assertStatuses(array_intersect_key($urls, array_flip(['dashboard', 'profile', 'documents', 'sectors', 'sector-requests', 'team', 'venues', 'venues.view'])), 200);
        $this->assertStatuses(array_intersect_key($urls, array_flip(['venues.create', 'bookings', 'schedule', 'orders', 'check-in', 'ledger', 'settlements', 'reviews'])), 403);
        $this->get($urls['dashboard'])
            ->assertSee(__('provider.onboarding.title'))
            ->assertSee(__('provider.banner.pending.title'))
            ->assertSee(__('provider.fields.iban'))
            ->assertDontSee('provider.onboarding.');

        $missing = OnboardingWidget::missingProfileFields($this->provider->fresh());
        $this->assertContains(__('provider.fields.iban'), $missing);
        $this->assertCount(2, OnboardingWidget::documentChecklist($this->provider, $this->provider->documents()->get()));
    }

    public function test_suspended_provider_is_read_only(): void
    {
        app(ProviderService::class)->suspend($this->provider, $this->admin, 'Test');
        $urls = $this->pageUrls($this->provider->fresh());

        $this->actingAs($this->owner, 'provider');
        $this->assertStatuses(array_intersect_key($urls, array_flip(['dashboard', 'venues', 'venues.view', 'bookings', 'schedule', 'orders', 'ledger', 'profile'])), 200);
        $this->assertStatuses(array_intersect_key($urls, array_flip(['venues.create', 'venues.edit', 'check-in'])), 403);
        $this->get($urls['dashboard'])->assertSee(__('provider.banner.suspended.title'));

        // The schedule is visible but nothing can be booked or blocked.
        $this->panel($this->provider->fresh());
        $this->assertFalse(Schedule::canBook());
        $this->assertFalse(Schedule::canBlock());
    }

    public function test_registration_creates_a_pending_provider_and_signs_in(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('provider'));

        Livewire::test(RegisterProvider::class)
            ->fillForm([
                'name' => 'New Owner',
                'email' => 'new.owner@example.com',
                'phone' => '0551234567',
                'password' => 'secret-pass-123',
                'passwordConfirmation' => 'secret-pass-123',
                'name_ar' => 'منشأة جديدة',
                'name_en' => 'New Co',
                'cr_number' => '1010101010',
                'maps_url' => 'https://maps.app.goo.gl/AbCdEf123456',
                'city_id' => City::first()->id,
                'sector_ids' => [Sector::first()->id],
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'new.owner@example.com')->firstOrFail();
        $provider = $user->ownedProvider;
        $this->assertSame(UserType::Provider, $user->type);
        $this->assertSame('+966551234567', $user->phone);
        $this->assertTrue(Hash::check('secret-pass-123', $user->password));
        $this->assertSame(ProviderStatus::Pending, $provider->status);
        $this->assertSame('establishment', $provider->type->value);
        $this->assertSame('https://maps.app.goo.gl/AbCdEf123456', $provider->maps_url);
        $this->assertTrue($provider->members()->where('user_id', $user->id)->where('role', 'owner')->exists());
        $this->assertAuthenticatedAs($user, 'provider');
        $this->get(Dashboard::getUrl(tenant: $provider))->assertOk();
    }

    public function test_profile_documents_sector_request_and_review_reply(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->provider->update(['iban' => 'SA0380000000608010167519']);
        $order = $this->booking();
        $review = Review::create([
            'user_id' => $this->customer('+966503333333')->id, 'order_id' => $order->id, 'venue_id' => $this->venue->id,
            'provider_id' => $this->provider->id, 'rating' => 5, 'comment' => 'Great tables', 'status' => 'published',
        ]);
        $this->asOwner();

        Livewire::test(EditProviderProfile::class)
            ->fillForm(['name_en' => 'Layali Updated', 'bio_ar' => 'نبذة جديدة'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Layali Updated', $this->provider->fresh()->name_en);

        $docs = $this->provider->documents()->count();
        Livewire::test(ManageDocuments::class)
            ->callAction('create', [
                'type' => 'license',
                'number' => 'LIC-1',
                'file_path' => UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf'),
            ])
            ->assertHasNoActionErrors();
        $this->assertSame($docs + 1, $this->provider->documents()->count());
        $this->assertSame('pending', $this->provider->documents()->latest('id')->first()->status->value);

        $sector = Sector::whereNotIn('id', $this->provider->sectors()->pluck('sectors.id'))->firstOrFail();
        Livewire::test(ManageSectorRequests::class)
            ->callAction('create', ['sector_id' => $sector->id, 'description' => 'please'])
            ->assertHasNoActionErrors();
        $this->assertTrue($this->provider->sectorRequests()->where('sector_id', $sector->id)->where('status', 'pending')->exists());

        Livewire::test(ManageReviews::class)
            ->assertCanSeeTableRecords([$review])
            ->callTableAction('reply', $review, ['reply' => 'شكراً لك'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('شكراً لك', $review->fresh()->provider_reply);
    }

    public function test_team_invite_grants_the_new_permission_names_and_notifies(): void
    {
        Notification::fake();
        $this->asOwner();

        $this->assertSame(
            ['venues.manage', 'orders.view', 'orders.manage', 'orders.offline', 'bookings.checkin', 'finance.view', 'team.manage', 'reviews.reply', 'profile.manage'],
            ProviderMember::PERMISSIONS,
        );
        foreach (MemberResource::permissionOptions() as $permission => $label) {
            $this->assertStringNotContainsString('provider.permissions', $label, $permission);
        }

        Livewire::test(ManageMembers::class)
            ->callAction('invite', [
                'name' => 'Door Staff', 'email' => 'door@example.com', 'phone' => '0559998887',
                'role' => 'staff', 'permissions' => ['bookings.checkin'],
                'access' => 'password', 'password' => 'temp-pass-123',
            ])
            ->assertHasNoActionErrors();

        $member = User::where('email', 'door@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('temp-pass-123', $member->password));
        $membership = ProviderMember::where('user_id', $member->id)->where('provider_id', $this->provider->id)->firstOrFail();
        $this->assertTrue($membership->allows('bookings.checkin'));
        $this->assertFalse($membership->allows('venues.manage'));
        $this->assertFalse($membership->allows('finance.view'));
        Notification::assertSentTo($member, SystemNotification::class);
    }

    public function test_global_search_is_scoped_to_the_tenant(): void
    {
        $mine = $this->booking();
        $other = $this->provider(null, 'Other');
        $foreignVenue = $this->venue($other, ['name_ar' => 'صالة الجيران', 'name_en' => 'Neighbour hall']);
        $foreign = $this->booking($foreignVenue);
        $this->asOwner();

        $search = Filament::getGlobalSearchProvider();
        $titles = fn (string $q) => collect($search->getResults($q)?->getCategories() ?? [])->flatten(1)->map(fn ($r) => (string) $r->title)->all();

        $this->assertContains($mine->number, $titles($mine->number));
        $this->assertNotContains($foreign->number, $titles($foreign->number));
        $this->assertContains($mine->bookings->first()->code, $titles($mine->bookings->first()->code));
        $this->assertSame([], $titles($foreign->bookings->first()->code));
        $this->assertContains($this->venue->name_ar, $titles(mb_substr($this->venue->name_ar, 0, 5)));
        $this->assertSame([], $titles('الجيران'));
    }
}
