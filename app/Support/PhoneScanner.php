<?php

namespace App\Support;

use App\Exceptions\BusinessException;
use App\Filament\Provider\Resources\Bookings\BookingResource;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Support\Fmt;
use App\Models\Provider;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\CheckInService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Use your phone as the scanner" on the provider's check-in page. The computer shows a QR; the
 * phone opens the link in it (no login: the long random token in the link is the key) and scans
 * customers' booking codes with its own camera. Every scan is checked in on the server and its
 * result is shown on both the phone and the computer.
 */
class PhoneScanner
{
    /** A link works for one working day, or until the provider disconnects it. */
    public const HOURS = 12;

    /** Opens a connection and returns its token. */
    public static function connect(Provider $provider, User $user, ?int $venueId = null): string
    {
        $token = Str::random(48);

        Cache::put(self::key($token), ['provider_id' => $provider->id, 'user_id' => $user->id, 'venue_id' => $venueId], now()->addHours(self::HOURS));

        return $token;
    }

    /** @return array{provider_id: int, user_id: int, venue_id: ?int}|null */
    public static function find(?string $token): ?array
    {
        return $token ? Cache::get(self::key($token)) : null;
    }

    public static function disconnect(?string $token): void
    {
        if ($token) {
            Cache::forget(self::key($token));
            Cache::forget(self::key($token).':results');
            Cache::forget(self::key($token).':seen');
        }
    }

    /** The phone opened the page or scanned: lets the computer show "phone connected". */
    public static function touch(string $token): void
    {
        Cache::put(self::key($token).':seen', now()->timestamp, now()->addHours(self::HOURS));
    }

    public static function phoneSeenRecently(string $token): bool
    {
        $seen = Cache::get(self::key($token).':seen');

        return $seen !== null && now()->timestamp - $seen < 90;
    }

    /** Leaves a result for the computer screen to pick up. */
    public static function push(string $token, array $result): void
    {
        Cache::lock(self::key($token).':lock', 5)->block(3, function () use ($token, $result) {
            $results = Cache::get(self::key($token).':results', []);
            $results[] = $result;
            Cache::put(self::key($token).':results', array_slice($results, -20), now()->addHours(self::HOURS));
        });
    }

    /** Results not yet shown on the computer (oldest first); taking them empties the list. */
    public static function pull(string $token): array
    {
        return Cache::lock(self::key($token).':lock', 5)->block(3, fn () => Cache::pull(self::key($token).':results', []));
    }

    /**
     * One check-in attempt, described for the screen (the same card for typed, laptop-camera and phone scans).
     *
     * @return array<string, mixed>
     */
    public static function attempt(Provider $provider, string $code, ?int $venueId, User $by): array
    {
        try {
            $booking = app(CheckInService::class)->checkIn($provider, $code, $venueId, $by);

            return [
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
            return ['ok' => false, 'message' => $e->getMessage(), 'code' => strtoupper(trim($code))];
        }
    }

    private static function key(string $token): string
    {
        return 'phone-scan:'.hash('sha256', $token);
    }
}
