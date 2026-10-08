<?php

namespace Tests\Feature\Provider;

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Filament\Provider\Pages\CheckIn;
use App\Filament\Provider\Pages\Schedule;
use App\Filament\Provider\Resources\Bookings\BookingResource;
use App\Filament\Provider\Resources\Bookings\Pages\ListBookings;
use App\Filament\Provider\Resources\Orders\OrderResource;
use App\Filament\Provider\Resources\Orders\Pages\ListOrders;
use App\Filament\Provider\Resources\Orders\Pages\ViewOrder;
use App\Filament\Provider\Resources\Orders\RelationManagers\BookingsRelationManager;
use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\Order;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * The day schedule (grid, block / unblock, counter booking), the bookings list and
 * arrival check-in. Time is frozen at 15:00 Riyadh so "today" has past and future slots.
 */
class ScheduleAndBookingsTest extends ProviderPanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::now('Asia/Riyadh')->setTime(15, 0));
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Riyadh')->format('Y-m-d');
    }

    /** Arguments of a clicked cell: table number $table (1-based) at $time on the day $days from today. */
    private function cell(int $table, string $time, int $days = 0): array
    {
        return [
            'resource' => $this->activity($this->venue)->resources[$table - 1]->id,
            'start' => $this->slot($days, $time)->toIso8601String(),
        ];
    }

    /** Status of one cell in the grid the page computed. */
    private function cellAt($page, int $table, string $time): array
    {
        $grid = $page->instance()->getGrid();
        $row = collect($grid['rows'])->firstWhere('time', $time);

        return $row['cells'][$table - 1];
    }

    public function test_schedule_shows_resources_slots_and_who_booked(): void
    {
        $order = $this->booking(startsAt: $this->slot(0, '20:00'), slots: 2);
        $booking = $order->bookings->first();
        $this->asOwner();

        $this->get(Schedule::getUrl(tenant: $this->provider))->assertOk()
            ->assertSee(__('provider.schedule.title'))
            ->assertSee('طاولة 1')->assertSee('طاولة 3')
            ->assertSee('Walk-in customer')
            ->assertSee($booking->code)
            ->assertDontSee('provider.schedule.');

        $page = Livewire::test(Schedule::class)
            ->assertSet('venueId', (string) $this->venue->id)
            ->assertSet('activityId', (string) $this->activity($this->venue)->id)
            ->assertSet('date', $this->today());

        $grid = $page->instance()->getGrid();
        $this->assertTrue($grid['is_open']);
        $this->assertSame(['10:00', '00:00'], [$grid['opens_at'], $grid['closes_at']]);
        $this->assertCount(3, $grid['resources']);
        $this->assertCount(14, $grid['rows']); // 10:00 -> 00:00 in 60-minute slots
        $this->assertSame(['10:00', '23:00'], [$grid['rows'][0]['time'], $grid['rows'][13]['time']]);

        // 15:00 now: earlier slots are over, the slot that started within the last hour can still be sold.
        $this->assertSame('past', $this->cellAt($page, 1, '13:00')['status']);
        $this->assertSame('available', $this->cellAt($page, 1, '15:00')['status']);
        $this->assertSame('available', $this->cellAt($page, 2, '20:00')['status']);

        // Both slots of the booking show the customer and the code, on table 1 only.
        foreach (['20:00', '21:00'] as $time) {
            $cell = $this->cellAt($page, 1, $time);
            $this->assertSame(['booked', 'Walk-in customer', $booking->code], [$cell['status'], $cell['customer'], $cell['code']]);
            $this->assertSame(OrderResource::getUrl('view', ['record' => $order]), $cell['url']);
        }
        $this->assertSame('available', $this->cellAt($page, 1, '22:00')['status']);
        $this->assertSame(2, $grid['counts']['booked']);

        // Day navigation; an invalid date falls back to today.
        $page->call('shiftDay', 1)->assertSet('date', CarbonImmutable::now('Asia/Riyadh')->addDay()->format('Y-m-d'))->assertDontSee($booking->code);
        $page->call('today')->assertSet('date', $this->today())->assertSee($booking->code);
        $page->set('date', 'nonsense')->assertSet('date', $this->today());
        $page->set('venueId', '999999')->assertSet('venueId', (string) $this->venue->id);
    }

    public function test_schedule_explains_closed_days_and_missing_set_up(): void
    {
        $this->asOwner();
        $weekday = CarbonImmutable::now('Asia/Riyadh')->dayOfWeek;
        $this->venue->hours()->where('weekday', $weekday)->update(['is_closed' => true, 'opens_at' => null, 'closes_at' => null]);

        Livewire::test(Schedule::class)->assertSee(__('provider.schedule.closed_day'));

        $this->activity($this->venue)->resources()->delete();
        Livewire::test(Schedule::class)->call('shiftDay', 1)->assertSee(__('provider.venues.no_resources'));

        $this->venue->activities()->delete();
        Livewire::test(Schedule::class)->assertSet('activityId', null)->assertSee(__('provider.schedule.no_activities'));

        $this->venue->forceDelete();
        Livewire::test(Schedule::class)->assertSet('venueId', null)->assertSee(__('provider.schedule.no_venues'));
    }

    public function test_block_and_unblock_a_slot(): void
    {
        $this->asOwner();
        $cell = $this->cell(2, '18:00');

        $page = Livewire::test(Schedule::class)
            ->callAction('slot', data: ['mode' => 'block', 'slots' => 2, 'reason' => 'صيانة'], arguments: $cell)
            ->assertHasNoActionErrors()
            ->assertNotified(__('provider.schedule.blocked_done'));

        $blocked = BookingSlot::where('resource_id', $cell['resource'])->orderBy('starts_at')->get();
        $this->assertCount(2, $blocked);
        $this->assertNull($blocked[0]->booking_id);
        $this->assertSame('صيانة', $blocked[0]->block_reason);
        $this->assertSame($this->owner->id, $blocked[0]->created_by);
        $this->assertTrue($blocked[0]->starts_at->equalTo($this->slot(0, '18:00')));

        $this->assertSame(['blocked', 'صيانة'], [$this->cellAt($page, 2, '18:00')['status'], $this->cellAt($page, 2, '18:00')['reason']]);
        $this->assertSame('blocked', $this->cellAt($page, 2, '19:00')['status']);
        $this->assertSame('available', $this->cellAt($page, 2, '20:00')['status']);
        $this->assertSame('available', $this->cellAt($page, 1, '18:00')['status']);
        $page->assertSee('صيانة');

        // A blocked slot cannot be sold: neither at the counter nor to app customers.
        $orders = Order::count();
        $page->callAction('slot', data: ['mode' => 'book', 'slots' => 1, 'customer' => ['name' => 'Late'], 'payment_method' => 'cash'], arguments: $cell)
            ->assertNotified(__('api.bookings.slot_taken'));
        $this->assertSame($orders, Order::count());

        // Open the second slot again.
        $page->callAction('unblock', arguments: ['slot' => $blocked[1]->id])->assertNotified(__('provider.schedule.unblocked_done'));
        $this->assertNull($blocked[1]->fresh());
        $this->assertSame('available', $this->cellAt($page, 2, '19:00')['status']);
        $this->assertSame('blocked', $this->cellAt($page, 2, '18:00')['status']);

        // A slot held by a booking is not a block and cannot be "unblocked".
        $held = $this->booking(startsAt: $this->slot(0, '21:00'), slots: 1)->bookings->first()->heldSlots()->first();
        $page->callAction('unblock', arguments: ['slot' => $held->id])->assertNotified(__('api.bookings.slot_taken'));
        $this->assertNotNull($held->fresh());

        // Slots outside the working hours are refused by the service.
        $page->callAction('slot', data: ['mode' => 'block', 'slots' => 3], arguments: $this->cell(1, '23:00'))
            ->assertNotified(__('api.bookings.outside_hours'));
    }

    public function test_counter_booking_for_a_walk_in_customer(): void
    {
        $this->asOwner();
        $cell = $this->cell(3, '19:00');

        $page = Livewire::test(Schedule::class)
            ->mountAction('slot', $cell)
            ->assertActionMounted('slot')
            ->assertMountedActionModalSee(['طاولة 3', $this->today().' 19:00', __('provider.schedule.book'), __('provider.schedule.block')]);
        $page->unmountAction();

        // Customer name and payment method are required for a booking.
        $page->callAction('slot', data: ['mode' => 'book', 'slots' => 2, 'customer' => ['name' => ''], 'payment_method' => null], arguments: $cell)
            ->assertHasActionErrors(['customer.name', 'payment_method']);
        $page->unmountAction();

        $page->callAction('slot', data: [
            'mode' => 'book', 'slots' => 2, 'payment_method' => 'pos',
            'customer' => ['name' => 'أحمد', 'phone' => '0551112223', 'notes' => 'عيد ميلاد'],
        ], arguments: $cell)
            ->assertHasNoActionErrors()
            ->assertNotified(__('provider.schedule.booked_done'));

        $order = $this->provider->orders()->latest('id')->firstOrFail();
        $this->assertSame(['offline', OrderStatus::Confirmed, 'pos', 'أحمد', '0551112223', 'عيد ميلاد', $this->owner->id, $this->venue->id],
            [$order->channel, $order->status, $order->offline_payment_method, $order->customer_name, $order->customer_phone, $order->notes, $order->created_by, $order->venue_id]);
        $this->assertSame(200.0, $order->total);

        $booking = $order->bookings()->firstOrFail();
        $this->assertSame([$cell['resource'], 2, BookingStatus::Confirmed], [$booking->resource_id, $booking->slots, $booking->status]);
        $this->assertTrue($booking->starts_at->equalTo($this->slot(0, '19:00')));
        $this->assertSame($this->today().' 19:00 - 21:00', BookingResource::window($booking));

        $this->assertSame(['booked', 'أحمد', $booking->code], array_values(array_intersect_key($this->cellAt($page, 3, '19:00'), array_flip(['status', 'customer', 'code']))));
        $this->assertSame('booked', $this->cellAt($page, 3, '20:00')['status']);
        $page->assertSee('أحمد')->assertSee($booking->code);

        // Same table, overlapping time: refused, no second order.
        $count = Order::count();
        $page->callAction('slot', data: ['mode' => 'book', 'slots' => 1, 'customer' => ['name' => 'Second'], 'payment_method' => 'cash'], arguments: $this->cell(3, '20:00'))
            ->assertNotified(__('api.bookings.slot_taken'));
        $this->assertSame($count, Order::count());

        // More slots than the activity allows per booking.
        $page->callAction('slot', data: ['mode' => 'book', 'slots' => 5, 'customer' => ['name' => 'Long'], 'payment_method' => 'cash'], arguments: $this->cell(1, '16:00'))
            ->assertNotified(__('api.bookings.slots_range', ['min' => 1, 'max' => 4]));
        $this->assertSame($count, Order::count());

        // The slot that is running right now can still be sold; one that ended cannot.
        $page->callAction('slot', data: ['mode' => 'book', 'slots' => 1, 'customer' => ['name' => 'Now'], 'payment_method' => 'cash'], arguments: $this->cell(1, '15:00'))
            ->assertNotified(__('provider.schedule.booked_done'));
        $page->callAction('slot', data: ['mode' => 'book', 'slots' => 1, 'customer' => ['name' => 'Gone'], 'payment_method' => 'cash'], arguments: $this->cell(1, '12:00'))
            ->assertNotified(__('api.bookings.in_the_past'));
        $this->assertSame($count + 1, Order::count());

        // A resource of another provider is not reachable through the arguments.
        $foreign = $this->activity($this->venue($this->provider(null, 'Other')))->resources->first();
        Livewire::test(Schedule::class)
            ->call('mountAction', 'slot', ['resource' => $foreign->id, 'start' => $this->slot(0, '22:00')->toIso8601String()])
            ->assertNotFound();
        $this->assertSame(0, Booking::where('resource_id', $foreign->id)->count());

        // The orders page points at the schedule for counter bookings; the order shows its booking.
        Livewire::test(ListOrders::class)->assertActionVisible('counter_booking')->assertActionHasUrl('counter_booking', Schedule::getUrl())
            ->assertCanSeeTableRecords([$order]);
        Livewire::test(BookingsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertCanSeeTableRecords([$booking])->assertSee($booking->code)->assertSee('19:00 - 21:00');
        $this->get(OrderResource::getUrl('view', ['record' => $order], tenant: $this->provider))->assertOk()->assertSee($this->venue->name_ar);
    }

    public function test_schedule_actions_follow_member_permissions(): void
    {
        $cell = $this->cell(1, '18:00');

        // orders.view: sees the schedule, cannot act.
        $this->actingAs($this->staff(['orders.view']), 'provider');
        $this->panel();
        $this->assertTrue(Schedule::canAccess());
        $this->assertFalse(Schedule::canBook());
        $this->assertFalse(Schedule::canBlock());
        Livewire::test(Schedule::class)->assertOk()->assertActionHidden('slot')->assertActionHidden('unblock')
            ->assertDontSee(__('provider.schedule.hint'));

        // orders.offline: books, but the block option does not exist for them.
        $this->flushSession();
        $this->actingAs($this->staff(['orders.offline'], 'cashier@example.com'), 'provider');
        $this->assertTrue(Schedule::canBook());
        $this->assertFalse(Schedule::canBlock());
        Livewire::test(Schedule::class)
            ->assertActionHidden('unblock')
            ->callAction('slot', data: ['mode' => 'block', 'slots' => 1], arguments: $cell)
            ->assertHasActionErrors(['mode']);
        $this->assertSame(0, BookingSlot::count());
        Livewire::test(Schedule::class)
            ->callAction('slot', data: ['mode' => 'book', 'slots' => 1, 'customer' => ['name' => 'Cash customer'], 'payment_method' => 'cash'], arguments: $cell)
            ->assertNotified(__('provider.schedule.booked_done'));
        $this->assertSame(1, $this->provider->orders()->count());

        // venues.manage: blocks, cannot book.
        $this->flushSession();
        $this->actingAs($this->staff(['venues.manage'], 'manager@example.com'), 'provider');
        $this->assertFalse(Schedule::canBook());
        $this->assertTrue(Schedule::canBlock());
        Livewire::test(Schedule::class)
            ->callAction('slot', data: ['mode' => 'book', 'slots' => 1, 'customer' => ['name' => 'No'], 'payment_method' => 'cash'], arguments: $this->cell(2, '18:00'))
            ->assertHasActionErrors(['mode']);
        $this->assertSame(1, $this->provider->orders()->count());
        Livewire::test(Schedule::class)
            ->callAction('slot', data: ['mode' => 'block', 'slots' => 1], arguments: $this->cell(2, '18:00'))
            ->assertNotified(__('provider.schedule.blocked_done'));
        $this->assertSame(1, BookingSlot::whereNull('booking_id')->count());

        // bookings.checkin only: no schedule at all.
        $this->flushSession();
        $this->actingAs($this->staff(['bookings.checkin'], 'door@example.com'), 'provider');
        $this->assertFalse(Schedule::canAccess());
        $this->assertTrue(CheckIn::canAccess());
    }

    public function test_check_in_page_checks_in_bookings_and_keeps_recent_results(): void
    {
        $first = $this->booking(startsAt: $this->slot(0, '20:00'), slots: 2)->bookings->first();
        $second = $this->booking(startsAt: $this->slot(0, '18:00'), slots: 1, resourceId: $this->activity($this->venue)->resources[1]->id)->bookings->first();
        $otherVenue = $this->venue($this->provider, ['name_ar' => 'الفرع الثاني', 'name_en' => 'Second branch']);
        $elsewhere = $this->booking($otherVenue, $this->slot(0, '19:00'), 1)->bookings->first();
        $foreign = $this->booking($this->venue($this->provider(null, 'Other')), $this->slot(0, '19:00'), 1)->bookings->first();
        $this->asOwner();

        $this->get(CheckIn::getUrl(tenant: $this->provider))
            ->assertOk()
            ->assertSee('farahCheckIn', false)
            ->assertSee('html5-qrcode', false)
            ->assertSee(__('provider.checkin.scan_camera'))
            ->assertSee(__('provider.checkin.any_venue'))
            ->assertSee('الفرع الثاني')
            ->assertDontSee('provider.checkin.');

        $page = Livewire::test(CheckIn::class)
            ->set('code', strtolower($first->code))->call('checkIn')
            ->assertSet('result.ok', true)
            ->assertSet('result.code', $first->code)
            ->assertSet('result.activity', 'بلياردو')
            ->assertSet('result.resource', 'طاولة 1')
            ->assertSet('result.window', $this->today().' 20:00 - 22:00')
            ->assertSet('result.customer', 'Walk-in customer')
            ->assertSet('result.order', $first->order->number)
            ->assertSet('result.time', $this->today().' 15:00')
            ->assertDispatched('farah-checkin-done', ok: true)
            ->assertSet('code', '')
            ->assertSee(__('provider.checkin.success'))
            ->assertSee('طاولة 1');

        $fresh = $first->fresh();
        $this->assertSame([BookingStatus::CheckedIn, $this->owner->id], [$fresh->status, $fresh->checked_in_by]);
        $this->assertNotNull($fresh->checked_in_at);

        // Same code again, another provider's code, and a code of a different venue than the one selected.
        $page->set('code', $first->code)->call('checkIn')
            ->assertSet('result.ok', false)
            ->assertDispatched('farah-checkin-done', ok: false);
        $page->set('code', $foreign->code)->call('checkIn')
            ->assertSet('result.ok', false)->assertSet('result.message', __('api.bookings.not_found'));
        $page->set('venueId', (string) $this->venue->id)->set('code', $elsewhere->code)->call('checkIn')
            ->assertSet('result.ok', false)->assertSet('result.message', __('api.bookings.not_found'));
        $this->assertSame(BookingStatus::Confirmed, $foreign->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $elsewhere->fresh()->status);

        $page->set('venueId', '')->set('code', $elsewhere->code)->call('checkIn')
            ->assertSet('result.ok', true)->assertSet('result.venue', 'الفرع الثاني');
        $page->set('code', $second->code)->call('checkIn')->assertSet('result.ok', true)->assertSet('result.resource', 'طاولة 2');

        $recent = $page->get('recent');
        $this->assertCount(5, $recent);
        // Six scans so far; the list keeps the five newest, newest first.
        $this->assertSame([true, true, false, false, false], array_column($recent, 'ok'));
        $this->assertSame($second->code, $recent[0]['code']);
        $page->assertSee(__('provider.checkin.recent'));

        $page->set('code', '')->call('checkIn')->assertHasErrors(['code' => 'required']);

        // A cancelled booking and one whose time passed are refused with the reason.
        app(OrderService::class)->releaseBookings([$second->fresh()]);
        $late = $this->booking(startsAt: $this->slot(0, '16:00'), slots: 1, resourceId: $this->activity($this->venue)->resources[2]->id)->bookings->first();
        $this->travelTo(CarbonImmutable::now('Asia/Riyadh')->setTime(17, 30));
        Livewire::test(CheckIn::class)->set('code', $late->code)->call('checkIn')
            ->assertSet('result.ok', false)->assertSet('result.message', __('api.bookings.time_passed'));
        Livewire::test(CheckIn::class)->set('code', $second->code)->call('checkIn')
            ->assertSet('result.ok', false)->assertSet('result.message', __('api.bookings.cancelled'));
    }

    public function test_bookings_list_filters_and_row_check_in(): void
    {
        $today = $this->booking(startsAt: $this->slot(0, '20:00'), slots: 1)->bookings->first();
        $upcoming = $this->booking(startsAt: $this->slot(2, '18:00'), slots: 2)->bookings->first();
        $running = $this->booking(startsAt: $this->slot(0, '15:00'), slots: 1, resourceId: $this->activity($this->venue)->resources[1]->id)->bookings->first();
        $foreign = $this->booking($this->venue($this->provider(null, 'Other')))->bookings->first();

        // One booking that is over.
        $this->travelTo(CarbonImmutable::now('Asia/Riyadh')->setTime(17, 0));
        app(OrderService::class)->completeFinished();
        $this->assertSame(BookingStatus::Completed, $running->fresh()->status);
        $this->asOwner();

        $this->get(BookingResource::getUrl(tenant: $this->provider))->assertOk()
            ->assertSee($today->code)
            ->assertSee($this->today().' 20:00 - 21:00')
            ->assertSee('طاولة 1')
            ->assertSee('Walk-in customer')
            ->assertDontSee($foreign->code);

        $list = Livewire::test(ListBookings::class)
            ->assertCanSeeTableRecords([$today, $upcoming, $running])
            ->assertCanNotSeeTableRecords([$foreign]);

        $list->filterTable('scope', 'today')->assertCanSeeTableRecords([$today, $running])->assertCanNotSeeTableRecords([$upcoming]);
        $list->filterTable('scope', 'upcoming')->assertCanSeeTableRecords([$today, $upcoming])->assertCanNotSeeTableRecords([$running]);
        $list->filterTable('scope', 'past')->assertCanSeeTableRecords([$running])->assertCanNotSeeTableRecords([$today, $upcoming]);
        $list->resetTableFilters()
            ->filterTable('status', ['completed'])->assertCanSeeTableRecords([$running])->assertCanNotSeeTableRecords([$today])
            ->resetTableFilters()
            ->searchTable($upcoming->code)->assertCanSeeTableRecords([$upcoming])->assertCanNotSeeTableRecords([$today])
            ->searchTable('Walk-in')->assertCanSeeTableRecords([$today, $upcoming])
            ->searchTable(null);

        // Check-in from the row: only for confirmed bookings that are not over.
        $list->assertTableActionVisible('check_in', $today)
            ->assertTableActionHidden('check_in', $running)
            ->callTableAction('check_in', $today)
            ->assertNotified(__('provider.checkin.success'));
        $this->assertSame(BookingStatus::CheckedIn, $today->fresh()->status);
        $list->assertTableActionHidden('check_in', $today->fresh());

        // The details modal renders.
        $list->mountTableAction('view', $upcoming)->assertMountedActionModalSee([$upcoming->code, $upcoming->order->number, 'طاولة 1']);

        // A member who only sees orders cannot check in.
        $this->flushSession();
        $this->actingAs($this->staff(['orders.view']), 'provider');
        Livewire::test(ListBookings::class)->assertCanSeeTableRecords([$upcoming])->assertTableActionHidden('check_in', $upcoming);
    }
}
