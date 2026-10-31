<?php

namespace App\Filament\Provider\Pages;

use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\Orders\OrderResource;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Filament\Support\SaudiInput;
use App\Models\Activity;
use App\Models\BookingSlot;
use App\Models\Resource;
use App\Models\Venue;
use App\Services\AvailabilityService;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Livewire\Attributes\Url;

/**
 * Day schedule of one activity: resources x time slots. Staff see what is free, booked
 * or blocked, take a slot out of sale, and book a walk-in customer at the counter.
 */
class Schedule extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = ProviderNavGroup::Bookings;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'schedule';

    protected string $view = 'filament.provider.schedule';

    #[Url]
    public ?string $venueId = null;

    #[Url]
    public ?string $activityId = null;

    /** Local calendar date (business timezone), Y-m-d. */
    #[Url]
    public ?string $date = null;

    public static function canAccess(): bool
    {
        return ProviderAccess::canView('schedule')
            && (ProviderAccess::allows('orders.view') || ProviderAccess::allows('orders.offline') || ProviderAccess::allows('venues.manage'));
    }

    public static function canBook(): bool
    {
        return ProviderAccess::canManage('schedule', 'orders.offline');
    }

    public static function canBlock(): bool
    {
        return ProviderAccess::canManage('schedule', 'venues.manage');
    }

    public static function getNavigationLabel(): string
    {
        return __('provider.schedule.title');
    }

    public function getTitle(): string
    {
        return __('provider.schedule.title');
    }

    public function mount(): void
    {
        $this->normalize();
    }

    public function updatedVenueId(): void
    {
        $this->activityId = null;
        $this->normalize();
    }

    public function updatedActivityId(): void
    {
        $this->normalize();
    }

    public function updatedDate(): void
    {
        $this->normalize();
    }

    public function shiftDay(int $days): void
    {
        $this->date = $this->day()->addDays($days)->format('Y-m-d');
    }

    public function today(): void
    {
        $this->date = CarbonImmutable::now(AvailabilityService::timezone())->format('Y-m-d');
    }

    /** Keep the selection valid: an unknown venue / activity / date falls back to the first one / today. */
    private function normalize(): void
    {
        $venues = $this->getVenueOptions();
        if (! isset($venues[(int) $this->venueId])) {
            $this->venueId = $venues === [] ? null : (string) array_key_first($venues);
        }

        $activities = $this->getActivityOptions();
        if (! isset($activities[(int) $this->activityId])) {
            $this->activityId = $activities === [] ? null : (string) array_key_first($activities);
        }

        $this->date = $this->day()->format('Y-m-d');
    }

    private function day(): CarbonImmutable
    {
        $tz = AvailabilityService::timezone();

        if ($this->date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) && strtotime($this->date) !== false) {
            return CarbonImmutable::parse($this->date, $tz)->startOfDay();
        }

        return CarbonImmutable::now($tz)->startOfDay();
    }

    /** @return array<int, string> */
    public function getVenueOptions(): array
    {
        return ProviderAccess::provider()->venues()->orderBy('name_ar')->get()
            ->mapWithKeys(fn (Venue $v) => [$v->id => VenueResource::title($v)])->all();
    }

    /** @return array<int, string> */
    public function getActivityOptions(): array
    {
        return $this->venue()?->activities()->get()
            ->mapWithKeys(fn (Activity $a) => [$a->id => Fmt::tr($a, 'name').($a->is_active ? '' : ' ('.__('provider.schedule.inactive').')')])->all() ?? [];
    }

    public function venue(): ?Venue
    {
        return $this->venueId ? ProviderAccess::provider()->venues()->with('hours')->find((int) $this->venueId) : null;
    }

    public function activity(): ?Activity
    {
        $venue = $this->venue();

        return $venue && $this->activityId ? $venue->activities()->with('resources')->find((int) $this->activityId)?->setRelation('venue', $venue) : null;
    }

    /**
     * The day as rows (time slots) x columns (resources).
     *
     * @return array{is_open: bool, opens_at: ?string, closes_at: ?string, slot_minutes: int, slot_price: float,
     *               resources: array<int, array{id: int, name: string}>, rows: array<int, array{time: string, end: string, cells: array<int, array<string, mixed>>}>,
     *               counts: array{available: int, booked: int, blocked: int}}|null
     */
    public function getGrid(): ?array
    {
        $activity = $this->activity();
        if (! $activity) {
            return null;
        }

        $tz = AvailabilityService::timezone();
        $day = app(AvailabilityService::class)->forDate($activity, $this->day());
        $first = $day['resources'][0]['slots'] ?? [];

        // Who holds each slot: a booking (with its customer) or a block made by the provider.
        $held = [];
        if ($first !== []) {
            BookingSlot::whereIn('resource_id', collect($day['resources'])->map(fn ($r) => $r['resource']->id))
                ->whereBetween('starts_at', [$first[0]['starts_at'], end($first)['starts_at']])
                ->with('booking.order.user')
                ->get()
                ->each(function (BookingSlot $slot) use (&$held) {
                    $held[$slot->resource_id][$slot->starts_at->getTimestamp()] = $slot;
                });
        }

        // A slot that already started can still be sold at the counter while it is running.
        $runningSince = CarbonImmutable::now()->subMinutes($activity->slot_minutes);
        $counts = ['available' => 0, 'booked' => 0, 'blocked' => 0];
        $rows = [];

        foreach ($first as $i => $slot) {
            $cells = [];
            foreach ($day['resources'] as $column) {
                $start = $column['slots'][$i]['starts_at'];
                $taken = $held[$column['resource']->id][$start->getTimestamp()] ?? null;
                $cell = ['resource' => $column['resource']->id, 'start' => $start->toIso8601String()];

                if ($taken?->booking) {
                    $cell += [
                        'status' => 'booked',
                        'customer' => $taken->booking->order?->customerDisplayName() ?? '-',
                        'code' => $taken->booking->code,
                        'booking_status' => $taken->booking->status->getLabel(),
                        'checked_in' => $taken->booking->checked_in_at !== null,
                        'url' => $taken->booking->order && OrderResource::canViewAny()
                            ? OrderResource::getUrl('view', ['record' => $taken->booking->order_id]) : null,
                    ];
                } elseif ($taken) {
                    $cell += ['status' => 'blocked', 'slot' => $taken->id, 'reason' => $taken->block_reason === 'blocked' ? null : $taken->block_reason];
                } elseif ($column['slots'][$i]['status'] === 'past' && $start->lessThanOrEqualTo($runningSince)) {
                    $cell += ['status' => 'past'];
                } else {
                    $cell += ['status' => 'available'];
                }

                if (isset($counts[$cell['status']])) {
                    $counts[$cell['status']]++;
                }
                $cells[] = $cell;
            }

            $rows[] = [
                'time' => $slot['starts_at']->setTimezone($tz)->format('H:i'),
                'end' => $slot['ends_at']->setTimezone($tz)->format('H:i'),
                'cells' => $cells,
            ];
        }

        return [
            'is_open' => $day['is_open'],
            'opens_at' => $day['opens_at'],
            'closes_at' => $day['closes_at'],
            'slot_minutes' => $day['slot_minutes'],
            'slot_price' => $day['slot_price'],
            'resources' => collect($day['resources'])->map(fn ($r) => ['id' => $r['resource']->id, 'name' => Fmt::tr($r['resource'], 'name')])->all(),
            'rows' => $rows,
            'counts' => $counts,
        ];
    }

    /** The clicked cell: a resource of the selected activity and the slot start (UTC). */
    private function target(array $arguments): array
    {
        $activity = $this->activity();
        abort_unless($activity, 404);

        /** @var resource $resource */
        $resource = $activity->resources->firstWhere('id', (int) ($arguments['resource'] ?? 0));
        abort_unless($resource, 404);
        $resource->setRelation('activity', $activity);

        // The start comes from the browser: a malformed value is a 404, not a server error.
        try {
            $start = CarbonImmutable::parse((string) ($arguments['start'] ?? ''))->utc();
        } catch (\Throwable) {
            abort(404);
        }

        return [$activity, $resource, $start];
    }

    /** A free slot was clicked: book it for a walk-in customer (OrderService::createOffline) or take it out of sale. */
    public function slotAction(): Action
    {
        $modes = array_filter([
            'book' => static::canBook() ? __('provider.schedule.book') : null,
            'block' => static::canBlock() ? __('provider.schedule.block') : null,
        ]);

        return Action::make('slot')
            ->visible($modes !== [])
            ->modalWidth('2xl')
            ->modalHeading(function (array $arguments) {
                [, $resource, $start] = $this->target($arguments);

                return Fmt::tr($resource, 'name').' — '.$start->setTimezone(AvailabilityService::timezone())->format('Y-m-d H:i');
            })
            ->modalSubmitActionLabel(__('provider.schedule.confirm'))
            ->schema(function () use ($modes) {
                $activity = $this->activity();

                return [
                    Radio::make('mode')->label(__('provider.schedule.what'))->options($modes)->default(array_key_first($modes))->inline()->live()->required(),
                    TextInput::make('slots')->label(__('provider.fields.slots'))->integer()->required()->minValue(1)->maxValue(48)
                        ->default($activity?->min_slots ?? 1)
                        ->helperText($activity ? __('provider.schedule.slots_hint', [
                            'minutes' => $activity->slot_minutes, 'price' => Fmt::sar($activity->slotPrice()),
                            'min' => $activity->min_slots, 'max' => $activity->max_slots,
                        ]) : null),
                    TextInput::make('customer.name')->label(__('provider.fields.customer_name'))->maxLength(120)
                        ->visible(fn (Get $get) => $get('mode') === 'book')->required(fn (Get $get) => $get('mode') === 'book'),
                    SaudiInput::mobile('customer.phone')->label(__('provider.fields.phone'))
                        ->visible(fn (Get $get) => $get('mode') === 'book'),
                    Select::make('payment_method')->label(__('provider.fields.payment_method'))
                        ->options(collect(['cash', 'transfer', 'pos'])->mapWithKeys(fn ($m) => [$m => __("provider.orders.payment_methods.{$m}")]))
                        ->default('cash')
                        ->visible(fn (Get $get) => $get('mode') === 'book')->required(fn (Get $get) => $get('mode') === 'book'),
                    Textarea::make('customer.notes')->label(__('provider.fields.notes'))->rows(2)->maxLength(1000)
                        ->visible(fn (Get $get) => $get('mode') === 'book'),
                    TextInput::make('reason')->label(__('provider.fields.block_reason'))->maxLength(190)
                        ->placeholder(__('provider.schedule.block_reason_placeholder'))
                        ->visible(fn (Get $get) => $get('mode') === 'block'),
                ];
            })
            ->action(function (array $data, array $arguments) {
                [$activity, $resource, $start] = $this->target($arguments);
                $slots = (int) $data['slots'];

                if (($data['mode'] ?? null) === 'block') {
                    abort_unless(static::canBlock(), 403);
                    Act::run(
                        fn () => app(AvailabilityService::class)->block($resource, $start, $slots, $data['reason'] ?? null, auth()->user()),
                        __('provider.schedule.blocked_done'),
                    );

                    return;
                }

                abort_unless(static::canBook(), 403);
                Act::run(fn () => app(OrderService::class)->createOffline(
                    ProviderAccess::provider(),
                    [['activity_id' => $activity->id, 'resource_id' => $resource->id, 'starts_at' => $start->toIso8601String(), 'slots' => $slots]],
                    (array) ($data['customer'] ?? []),
                    $data['payment_method'],
                    auth()->user(),
                ), __('provider.schedule.booked_done'));
            });
    }

    /** Put a blocked slot back on sale. */
    public function unblockAction(): Action
    {
        return Action::make('unblock')
            ->visible(static::canBlock())
            ->requiresConfirmation()
            ->modalHeading(__('provider.schedule.unblock'))
            ->modalDescription(__('provider.schedule.unblock_hint'))
            ->action(function (array $arguments) {
                abort_unless(static::canBlock(), 403);
                $activity = $this->activity();
                abort_unless($activity, 404);

                $slot = BookingSlot::whereKey((int) ($arguments['slot'] ?? 0))
                    ->whereIn('resource_id', $activity->resources->pluck('id'))
                    ->firstOrFail();

                Act::run(fn () => app(AvailabilityService::class)->unblock($slot), __('provider.schedule.unblocked_done'));
            });
    }
}
