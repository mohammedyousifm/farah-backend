<?php

namespace Tests\Feature\Provider;

use App\Enums\VenueStatus;
use App\Filament\Provider\Pages\Dashboard;
use App\Filament\Provider\Resources\Venues\Pages\CreateVenue;
use App\Filament\Provider\Resources\Venues\Pages\EditVenue;
use App\Filament\Provider\Resources\Venues\Pages\ListVenues;
use App\Filament\Provider\Resources\Venues\Pages\ViewVenue;
use App\Filament\Provider\Resources\Venues\RelationManagers\ActivitiesRelationManager;
use App\Filament\Provider\Resources\Venues\RelationManagers\ResourcesRelationManager;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Provider\Widgets\OnboardingWidget;
use App\Models\Sector;
use App\Models\Venue;
use App\Services\VenueService;
use Livewire\Livewire;

/**
 * Venues in the provider panel: create wizard with working hours, activities and
 * numbered resources, submit for review, editing rules and the set-up checklist.
 */
class VenueManagementTest extends ProviderPanelTestCase
{
    /** Form state of the weekly hours: every day $open -> $close, with overrides per weekday. */
    private function hours(array $overrides = [], string $open = '10:00', string $close = '00:00'): array
    {
        $rows = [];
        foreach (range(0, 6) as $day) {
            $rows[$day] = ($overrides[$day] ?? []) + ['opens_at' => $open, 'closes_at' => $close, 'is_closed' => false];
        }

        return $rows;
    }

    public function test_create_page_is_a_wizard_with_the_form_sections(): void
    {
        $this->asOwner();

        $this->assertSame(['details', 'location', 'hours', 'booking_rules'], array_keys(VenueResource::formSections()));
        $this->assertSame(['details', 'location', 'booking_rules'], array_keys(VenueResource::formSections(withHours: false)));

        $this->get(VenueResource::getUrl('create', tenant: $this->provider))
            ->assertOk()
            ->assertSee(__('provider.venues.steps.details'))
            ->assertSee(__('provider.venues.steps.hours'))
            ->assertSee(__('provider.venues.steps.booking_rules'));

        Livewire::test(CreateVenue::class)
            ->assertSee(__('provider.days.0'))
            ->assertSee(__('provider.days.6'))
            ->fillForm(['name_ar' => '', 'city_id' => null])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'required', 'city_id' => 'required']);
    }

    public function test_venue_is_created_with_hours_then_activities_resources_and_submitted(): void
    {
        $this->asOwner();

        Livewire::test(CreateVenue::class)
            ->fillForm([
                'name_ar' => 'صالة الاختبار',
                'name_en' => 'Test hall',
                'summary_ar' => 'بلياردو وبولينج',
                'city_id' => $this->city->id,
                'address' => 'طريق الملك فهد',
                'lat' => 24.7136,
                'lng' => 46.6753,
                'phone' => '+966112223344',
                'audience' => 'families',
                'min_age' => 12,
                'refund_policy' => 'flexible',
                'refund_policy_note' => 'قبل الموعد بساعتين',
                'advance_booking_days' => 7,
                // Friday closed, Saturday open past midnight.
                'hours' => $this->hours([5 => ['is_closed' => true], 6 => ['opens_at' => '16:00', 'closes_at' => '02:00']]),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $venue = Venue::where('name_ar', 'صالة الاختبار')->firstOrFail();
        $this->assertSame($this->provider->id, $venue->provider_id);
        $this->assertSame(VenueStatus::Draft, $venue->status);
        $this->assertSame('families', $venue->audience);
        $this->assertSame(7, (int) $venue->advance_booking_days);
        $this->assertSame('+966112223344', $venue->phone);

        $hours = $venue->hours()->get()->keyBy('weekday');
        $this->assertCount(7, $hours);
        $this->assertSame(['10:00', '00:00'], [$hours[0]->opens(), $hours[0]->closes()]);
        $this->assertTrue($hours[5]->is_closed);
        $this->assertNull($hours[5]->opens_at);
        $this->assertSame(['16:00', '02:00'], [$hours[6]->opens(), $hours[6]->closes()]);

        $view = ['record' => $venue->getRouteKey()];
        $managers = ['ownerRecord' => $venue, 'pageClass' => ViewVenue::class];

        // The view page explains the hours, including "until the next day".
        $this->get(VenueResource::getUrl('view', ['record' => $venue], tenant: $this->provider))->assertOk()
            ->assertSee('16:00 - 02:00 ('.__('provider.venues.next_day').')')
            ->assertSee(__('provider.days.5').': '.__('provider.venues.closed'));

        // Nothing to book yet: VenueService refuses the submission and says why.
        Livewire::test(ViewVenue::class, $view)->callAction('submit')->assertNotified(__('api.venues.no_activities'));
        $this->assertSame(VenueStatus::Draft, $venue->fresh()->status);

        // An activity type the provider is not approved for is refused.
        $foreignSector = Sector::where('slug', 'bowling')->firstOrFail();
        Livewire::test(ActivitiesRelationManager::class, $managers)
            ->callTableAction('create', data: ['sector_id' => $foreignSector->id, 'name_ar' => 'بولينج', 'price_per_hour' => 80, 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2]);
        $this->assertSame(0, $venue->activities()->count());

        Livewire::test(ActivitiesRelationManager::class, $managers)
            ->callTableAction('create', data: [
                'sector_id' => $this->sector->id, 'name_ar' => 'بلياردو', 'name_en' => 'Billiards', 'description_ar' => 'طاولات احترافية',
                'price_per_hour' => 60, 'slot_minutes' => 30, 'min_slots' => 2, 'max_slots' => 6, 'capacity' => 4, 'is_active' => true,
            ])
            ->assertHasNoTableActionErrors();
        $activity = $venue->activities()->firstOrFail();
        $this->assertSame([$this->sector->id, 60.0, 30, 2, 6, 4], [$activity->sector_id, $activity->price_per_hour, $activity->slot_minutes, $activity->min_slots, $activity->max_slots, (int) $activity->capacity]);
        $this->assertSame(30.0, $activity->slotPrice());
        $this->assertSame(60.0, $venue->fresh()->min_price);

        // max_slots below min_slots is a form error.
        Livewire::test(ActivitiesRelationManager::class, $managers)
            ->callTableAction('edit', $activity, data: ['min_slots' => 4, 'max_slots' => 2])
            ->assertHasTableActionErrors(['max_slots']);

        // An activity without resources is still not bookable.
        Livewire::test(ViewVenue::class, $view)->callAction('submit')->assertNotified(__('api.venues.no_activities'));

        // Quick add: 10 numbered tables, then 2 more from the activity row continue the numbering.
        Livewire::test(ResourcesRelationManager::class, $managers)
            ->callTableAction('add_many', data: ['activity_id' => $activity->id, 'count' => 10, 'prefix_ar' => 'طاولة', 'prefix_en' => 'Table'])
            ->assertHasNoTableActionErrors();
        Livewire::test(ActivitiesRelationManager::class, $managers)
            ->callTableAction('add_resources', $activity, data: ['count' => 2, 'prefix_ar' => 'طاولة', 'prefix_en' => 'Table'])
            ->assertHasNoTableActionErrors();
        $names = $activity->resources()->pluck('name_ar')->all();
        $this->assertCount(12, $names);
        $this->assertSame(['طاولة 1', 'طاولة 2', 'طاولة 10', 'طاولة 11', 'طاولة 12'], [$names[0], $names[1], $names[9], $names[10], $names[11]]);
        $this->assertSame('Table 12', $activity->resources()->get()->last()->name_en);

        // A single named resource, then rename / deactivate it.
        Livewire::test(ResourcesRelationManager::class, $managers)
            ->callTableAction('create', data: ['activity_id' => $activity->id, 'name_ar' => 'طاولة VIP', 'is_active' => true])
            ->assertHasNoTableActionErrors();
        $vip = $activity->resources()->where('name_ar', 'طاولة VIP')->firstOrFail();
        Livewire::test(ResourcesRelationManager::class, $managers)
            ->callTableAction('edit', $vip, data: ['activity_id' => $activity->id, 'name_ar' => 'الطاولة الذهبية', 'is_active' => false])
            ->assertHasNoTableActionErrors();
        $this->assertSame(['الطاولة الذهبية', false], [$vip->fresh()->name_ar, $vip->fresh()->is_active]);

        Livewire::test(ViewVenue::class, $view)->callAction('submit')->assertNotified(__('provider.venues.submitted'));
        $this->assertSame(VenueStatus::PendingReview, $venue->fresh()->status);
        $this->assertNotNull($venue->fresh()->submitted_at);

        // Already under review: the action is gone.
        Livewire::test(ViewVenue::class, $view)->assertActionHidden('submit');
    }

    public function test_hours_change_without_review_but_editing_details_goes_back_to_review(): void
    {
        $this->asOwner();
        $record = ['record' => $this->venue->getRouteKey()];
        $this->assertSame(VenueStatus::Published, $this->venue->status);

        // Working hours: saved directly, the venue stays live.
        Livewire::test(ViewVenue::class, $record)
            ->callAction('hours', data: ['hours' => $this->hours([1 => ['is_closed' => true]], '12:00', '23:00')])
            ->assertHasNoActionErrors()
            ->assertNotified(__('provider.venues.hours_saved'));
        $hours = $this->venue->hours()->get()->keyBy('weekday');
        $this->assertSame(['12:00', '23:00'], [$hours[0]->opens(), $hours[0]->closes()]);
        $this->assertTrue($hours[1]->is_closed);
        $this->assertSame(VenueStatus::Published, $this->venue->fresh()->status);

        // An open day needs both times.
        Livewire::test(ViewVenue::class, $record)
            ->callAction('hours', data: ['hours' => $this->hours([2 => ['opens_at' => null]])])
            ->assertHasActionErrors(['hours.2.opens_at']);

        // The same action is offered from the list.
        Livewire::test(ListVenues::class)->assertCanSeeTableRecords([$this->venue])->assertTableActionVisible('hours', $this->venue);

        // Details: saved through VenueService::update, which sends a live venue back to review.
        $this->get(VenueResource::getUrl('edit', ['record' => $this->venue], tenant: $this->provider))->assertOk()
            ->assertSee(__('provider.venues.edit_goes_to_review'));
        Livewire::test(EditVenue::class, $record)
            ->fillForm(['name_en' => 'Renamed venue', 'advance_booking_days' => 30])
            ->call('save')
            ->assertHasNoFormErrors();
        $fresh = $this->venue->fresh();
        $this->assertSame(['Renamed venue', 30, VenueStatus::PendingReview], [$fresh->name_en, (int) $fresh->advance_booking_days, $fresh->status]);
        // Hours were not touched by the details form.
        $this->assertSame('12:00', $fresh->hours()->where('weekday', 0)->first()->opens());
    }

    public function test_suspended_venue_is_locked_and_records_with_bookings_cannot_be_deleted(): void
    {
        $order = $this->booking();
        $booking = $order->bookings->first();
        $this->asOwner();
        $managers = ['ownerRecord' => $this->venue, 'pageClass' => ViewVenue::class];

        // Bookings keep their activity and resource.
        Livewire::test(ActivitiesRelationManager::class, $managers)->callTableAction('delete', $booking->activity)
            ->assertNotified(__('provider.venues.activity_has_bookings'));
        Livewire::test(ResourcesRelationManager::class, $managers)->callTableAction('delete', $booking->resource)
            ->assertNotified(__('provider.venues.resource_has_bookings'));
        $this->assertNotNull($booking->activity->fresh());
        $this->assertNotNull($booking->resource->fresh());

        // A free resource can go.
        $free = $this->activity($this->venue)->resources->last();
        Livewire::test(ResourcesRelationManager::class, $managers)->callTableAction('delete', $free);
        $this->assertNull($free->fresh());

        // A venue with orders cannot be deleted.
        $this->assertFalse(VenueResource::canDelete($this->venue));

        app(VenueService::class)->suspend($this->venue, $this->admin, 'Complaint');
        $suspended = $this->venue->fresh();
        $this->assertFalse(VenueResource::canEdit($suspended));
        $this->get(VenueResource::getUrl('edit', ['record' => $suspended], tenant: $this->provider))->assertForbidden();
        $this->get(VenueResource::getUrl('view', ['record' => $suspended], tenant: $this->provider))->assertOk()->assertSee('Complaint');
        Livewire::test(ViewVenue::class, ['record' => $suspended->getRouteKey()])->assertActionHidden('hours')->assertActionHidden('submit');
        Livewire::test(ActivitiesRelationManager::class, ['ownerRecord' => $suspended, 'pageClass' => ViewVenue::class])
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $booking->activity);
    }

    public function test_staff_without_venues_manage_sees_venues_read_only(): void
    {
        $this->actingAs($this->staff(['orders.view']), 'provider');
        $this->panel();

        $this->assertFalse(VenueResource::canCreate());
        $this->assertFalse(VenueResource::canEdit($this->venue));
        Livewire::test(ViewVenue::class, ['record' => $this->venue->getRouteKey()])->assertActionHidden('hours')->assertActionHidden('submit');
        Livewire::test(ActivitiesRelationManager::class, ['ownerRecord' => $this->venue, 'pageClass' => ViewVenue::class])
            ->assertOk()
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('add_resources', $this->activity($this->venue));
        Livewire::test(ResourcesRelationManager::class, ['ownerRecord' => $this->venue, 'pageClass' => ViewVenue::class])
            ->assertOk()
            ->assertTableActionHidden('add_many')
            ->assertTableActionHidden('create');
    }

    public function test_dashboard_guides_an_approved_provider_until_a_venue_is_live(): void
    {
        // Published venue: no checklist.
        $this->asOwner();
        $this->assertFalse(OnboardingWidget::needsVenueSetup($this->provider));
        $this->get(Dashboard::getUrl(tenant: $this->provider))->assertOk()->assertDontSee(__('provider.onboarding.venue_title'));

        // No venue at all: every step is open and points at "create venue".
        $this->venue->forceDelete();
        $this->assertTrue(OnboardingWidget::needsVenueSetup($this->provider));
        $steps = collect(OnboardingWidget::venueSteps($this->provider))->keyBy('key');
        $this->assertSame(['venue', 'hours', 'activities', 'submit'], $steps->keys()->all());
        $this->assertSame([false, false, false, false], $steps->pluck('done')->all());
        $this->assertSame(VenueResource::getUrl('create'), $steps['venue']['url']);
        $this->get(Dashboard::getUrl(tenant: $this->provider))->assertOk()
            ->assertSee(__('provider.onboarding.venue_title'))
            ->assertSee(__('provider.onboarding.step_activities'))
            ->assertDontSee('provider.onboarding.');

        // Draft venue with hours but nothing to book.
        $draft = app(VenueService::class)->create($this->provider, ['name_ar' => 'مسودة', 'city_id' => $this->city->id]);
        $this->assertSame([true, true, false, false], array_column(OnboardingWidget::venueSteps($this->provider), 'done'));

        // Activity without resources, then with resources.
        $activity = $draft->activities()->create(['sector_id' => $this->sector->id, 'name_ar' => 'بلياردو', 'price_per_hour' => 50]);
        $steps = OnboardingWidget::venueSteps($this->provider);
        $this->assertFalse($steps[2]['done']);
        $this->assertSame(__('provider.onboarding.resources_todo'), $steps[2]['detail']);
        $activity->resources()->create(['name_ar' => 'طاولة 1']);
        $this->assertSame([true, true, true, false], array_column(OnboardingWidget::venueSteps($this->provider), 'done'));

        // Submitted: the checklist says it is with the Farah team; once published it disappears.
        app(VenueService::class)->submit($draft);
        $this->assertSame([true, true, true, true], array_column(OnboardingWidget::venueSteps($this->provider), 'done'));
        Livewire::test(OnboardingWidget::class)->assertSee(__('provider.onboarding.venue_ready'));
        app(VenueService::class)->approve($draft->fresh(), $this->admin);
        $this->assertFalse(OnboardingWidget::needsVenueSetup($this->provider));
    }
}
