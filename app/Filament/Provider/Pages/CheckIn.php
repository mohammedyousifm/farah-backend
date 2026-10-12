<?php

namespace App\Filament\Provider\Pages;

use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Models\Venue;
use App\Services\AvailabilityService;
use App\Support\PhoneScanner;
use chillerlan\QRCode\QRCode;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

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

    /** Set while a phone is linked as this screen's scanner (see PhoneScanner). */
    #[Locked]
    public ?string $phoneToken = null;

    public function mount(): void
    {
        // Coming back to the page keeps the phone that is already linked.
        $token = session('farah.phone_scan');
        $link = PhoneScanner::find($token);

        if ($link && $link['provider_id'] === ProviderAccess::provider()?->id && $link['user_id'] === auth()->id()) {
            $this->phoneToken = $token;
        }
    }

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

    public function checkIn(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->validate(['code' => ['required', 'string', 'max:30'], 'venueId' => ['nullable', 'integer']],
            [], ['code' => __('provider.checkin.code')]);

        $venueId = $this->venueId ? (int) $this->venueId : null;
        if ($venueId && ! ProviderAccess::provider()->venues()->whereKey($venueId)->exists()) {
            $venueId = null;
        }

        $this->show(PhoneScanner::attempt(ProviderAccess::provider(), $this->code, $venueId, auth()->user()));

        $this->code = '';
    }

    /** Opens the phone link: the QR shown next lets a phone scan for this screen. */
    public function connectPhone(): void
    {
        abort_unless(static::canAccess(), 403);

        PhoneScanner::disconnect($this->phoneToken);

        $venueId = $this->venueId ? (int) $this->venueId : null;
        if ($venueId && ! ProviderAccess::provider()->venues()->whereKey($venueId)->exists()) {
            $venueId = null;
        }

        $this->phoneToken = PhoneScanner::connect(ProviderAccess::provider(), auth()->user(), $venueId);
        session(['farah.phone_scan' => $this->phoneToken]);
    }

    public function disconnectPhone(): void
    {
        PhoneScanner::disconnect($this->phoneToken);
        $this->phoneToken = null;
        session()->forget('farah.phone_scan');
    }

    /** Called every two seconds while a phone link is open: shows what the phone scanned. */
    public function pollPhone(): void
    {
        if (! $this->phoneToken) {
            return;
        }

        if (PhoneScanner::find($this->phoneToken) === null) {
            $this->disconnectPhone();

            return;
        }

        foreach (PhoneScanner::pull($this->phoneToken) as $result) {
            $this->show($result);
        }
    }

    public function phoneUrl(): ?string
    {
        if (! $this->phoneToken) {
            return null;
        }

        // A computer opened as "localhost" would hand the phone an address it cannot reach.
        return in_array(request()->getHost(), ['localhost', '127.0.0.1'], true)
            ? rtrim((string) config('app.url'), '/').route('provider.phone-scan', $this->phoneToken, false)
            : route('provider.phone-scan', $this->phoneToken);
    }

    /** The link as a QR image (SVG data URI) for the phone's camera. */
    public function phoneQr(): ?string
    {
        return $this->phoneToken ? (new QRCode)->render($this->phoneUrl()) : null;
    }

    public function phoneOnline(): bool
    {
        return $this->phoneToken !== null && PhoneScanner::phoneSeenRecently($this->phoneToken);
    }

    /** Puts one result on the screen and at the top of the recent list. */
    private function show(array $result): void
    {
        $this->result = $result;

        array_unshift($this->recent, [
            'ok' => $this->result['ok'],
            'code' => $this->result['code'],
            'label' => $this->result['ok'] ? trim(($this->result['customer'] ?? '').' · '.($this->result['resource'] ?? ''), ' ·') : $this->result['message'],
            'time' => now(AvailabilityService::timezone())->format('H:i:s'),
        ]);
        $this->recent = array_slice($this->recent, 0, 5);

        $this->dispatch('farah-checkin-done', ok: $this->result['ok']);
    }
}
