<?php

namespace App\Filament\Provider\Pages;

use App\Exceptions\BusinessException;
use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\Bookings\BookingResource;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Fmt;
use App\Models\Venue;
use App\Services\AvailabilityService;
use App\Services\CheckInService;
use Filament\Pages\Page;

/** Arrival check-in: type or scan a booking code (same rules as the provider API). */
class CheckIn extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = ProviderNavGroup::Bookings;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'check-in';

    protected string $view = 'filament.provider.check-in';

    public string $code = '';

    public ?string $venueId = null;

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    /** Last few results on this screen (newest first), so counter staff can glance back. */
    public array $recent = [];

    public static function canAccess(): bool
    {
        return ProviderAccess::canManage('checkin', 'bookings.checkin');
    }

    public static function getNavigationLabel(): string
    {
        return __('provider.checkin.title');
    }

    public function getTitle(): string
    {
        return __('provider.checkin.title');
    }

    /** @return array<int, string> */
    public function getVenueOptions(): array
    {
        return ProviderAccess::provider()->venues()->orderBy('name_ar')->get()
            ->mapWithKeys(fn (Venue $v) => [$v->id => VenueResource::title($v)])
            ->all();
    }

    public function checkIn(CheckInService $service): void
    {
        abort_unless(static::canAccess(), 403);

        $this->validate(['code' => ['required', 'string', 'max:30'], 'venueId' => ['nullable', 'integer']],
            [], ['code' => __('provider.checkin.code')]);

        $venueId = $this->venueId ? (int) $this->venueId : null;
        if ($venueId && ! ProviderAccess::provider()->venues()->whereKey($venueId)->exists()) {
            $venueId = null;
        }

        try {
            $booking = $service->checkIn(ProviderAccess::provider(), $this->code, $venueId, auth()->user());

            $this->result = [
                'ok' => true,
                'message' => __('provider.checkin.success'),
                'code' => $booking->code,
                'activity' => $booking->activity ? Fmt::tr($booking->activity, 'name') : $booking->name,
                'resource' => $booking->resource ? Fmt::tr($booking->resource, 'name') : null,
                'venue' => $booking->venue ? VenueResource::title($booking->venue) : null,
                'window' => BookingResource::window($booking),
                'customer' => $booking->order->customerDisplayName(),
                'order' => $booking->order->number,
                'time' => $booking->checked_in_at?->timezone(AvailabilityService::timezone())->format('Y-m-d H:i'),
            ];
        } catch (BusinessException $e) {
            $this->result = ['ok' => false, 'message' => $e->getMessage(), 'code' => strtoupper(trim($this->code))];
        }

        array_unshift($this->recent, [
            'ok' => $this->result['ok'],
            'code' => $this->result['code'],
            'label' => $this->result['ok'] ? trim(($this->result['customer'] ?? '').' · '.($this->result['resource'] ?? ''), ' ·') : $this->result['message'],
            'time' => now(AvailabilityService::timezone())->format('H:i:s'),
        ]);
        $this->recent = array_slice($this->recent, 0, 5);

        $this->code = '';
        $this->dispatch('farah-checkin-done', ok: $this->result['ok']);
    }
}
