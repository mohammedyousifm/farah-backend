<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Exceptions\BusinessException;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Arrival check-in of a booking code at the venue (used by the provider API and the web dashboard). */
class CheckInService
{
    private const WITH = ['activity', 'resource', 'venue.city', 'order'];

    public function checkIn(Provider $provider, string $code, ?int $venueId, User $by): Booking
    {
        return DB::transaction(function () use ($provider, $code, $venueId, $by) {
            $booking = Booking::where('code', strtoupper(trim($code)))
                ->where('provider_id', $provider->id)
                ->when($venueId, fn ($q, $id) => $q->where('venue_id', $id))
                ->with(self::WITH)
                ->lockForUpdate()
                ->first();

            if (! $booking) {
                throw new BusinessException(__('api.bookings.not_found'), 'booking_not_found', 404);
            }
            if ($booking->status === BookingStatus::CheckedIn) {
                throw new BusinessException(__('api.bookings.already_checked_in', ['time' => $booking->checked_in_at?->timezone(AvailabilityService::timezone())->format('Y-m-d H:i')]), 'booking_used', 409);
            }
            if ($booking->status === BookingStatus::Completed || $booking->ends_at->isPast()) {
                throw new BusinessException(__('api.bookings.time_passed'), 'booking_finished', 409);
            }
            if ($booking->status !== BookingStatus::Confirmed) {
                throw new BusinessException(__('api.bookings.cancelled'), 'booking_cancelled', 409);
            }

            $booking->update(['status' => BookingStatus::CheckedIn, 'checked_in_at' => now(), 'checked_in_by' => $by->id]);

            return $booking->fresh(self::WITH);
        });
    }
}
