<?php

namespace App\Services;

use App\Enums\VenueStatus;
use App\Exceptions\BusinessException;
use App\Models\Provider;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Venue lifecycle: draft → pending_review → published / rejected → suspended. */
class VenueService
{
    public function create(Provider $provider, array $data, ?array $hours = null): Venue
    {
        return DB::transaction(function () use ($provider, $data, $hours) {
            $venue = $provider->venues()->create(array_merge($data, [
                'slug' => $this->slug($data['name_en'] ?? $data['name_ar']),
                'status' => VenueStatus::Draft,
            ]));
            $this->syncHours($venue, $hours ?? self::defaultHours());

            return $venue;
        });
    }

    public function update(Venue $venue, array $data, ?array $hours = null): Venue
    {
        // Editing a live venue sends it back to review so admins see the change.
        $needsReview = in_array($venue->status, [VenueStatus::Published, VenueStatus::Rejected], true);

        DB::transaction(function () use ($venue, $data, $hours, $needsReview) {
            $venue->update($data + ($needsReview ? ['status' => VenueStatus::PendingReview, 'submitted_at' => now()] : []));
            if ($hours !== null) {
                $this->syncHours($venue, $hours);
            }
        });

        return $venue->fresh();
    }

    /**
     * Replace the weekly working hours. Changing hours does not touch existing bookings.
     *
     * @param  array<int, array{weekday: int, opens_at?: ?string, closes_at?: ?string, is_closed?: bool}>  $hours
     */
    public function syncHours(Venue $venue, array $hours): void
    {
        foreach ($hours as $row) {
            $weekday = (int) $row['weekday'];
            if ($weekday < 0 || $weekday > 6) {
                continue;
            }
            $closed = (bool) ($row['is_closed'] ?? false) || empty($row['opens_at']) || empty($row['closes_at']);

            $venue->hours()->updateOrCreate(['weekday' => $weekday], [
                'opens_at' => $closed ? null : $row['opens_at'],
                'closes_at' => $closed ? null : $row['closes_at'],
                'is_closed' => $closed,
            ]);
        }

        $venue->unsetRelation('hours');
    }

    /** Open every day 10:00 → 00:00 until the provider sets real hours. */
    public static function defaultHours(): array
    {
        return array_map(fn (int $d) => ['weekday' => $d, 'opens_at' => '10:00', 'closes_at' => '00:00', 'is_closed' => false], range(0, 6));
    }

    public function submit(Venue $venue): Venue
    {
        if (! in_array($venue->status, [VenueStatus::Draft, VenueStatus::Rejected], true)) {
            throw new BusinessException(__('api.venues.cannot_submit'), 'wrong_status');
        }
        if (! $venue->provider->isApproved()) {
            throw new BusinessException(__('api.venues.provider_not_approved'), 'provider_not_approved');
        }
        if (! $venue->activities()->where('is_active', true)->whereHas('resources', fn ($q) => $q->where('is_active', true))->exists()) {
            throw new BusinessException(__('api.venues.no_activities'), 'no_activities');
        }
        if (! $venue->hours()->where('is_closed', false)->exists()) {
            throw new BusinessException(__('api.venues.no_hours'), 'no_hours');
        }

        $venue->update(['status' => VenueStatus::PendingReview, 'submitted_at' => now()]);

        return $venue;
    }

    public function approve(Venue $venue, User $by): void
    {
        $venue->update([
            'status' => VenueStatus::Published,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
            'published_at' => $venue->published_at ?? now(),
            'rejection_reason' => null,
        ]);

        SystemNotification::send($venue->provider->owner, 'venue_approved', ['venue' => $venue->name_ar], ['venue_id' => $venue->id]);
    }

    public function reject(Venue $venue, User $by, string $reason): void
    {
        $venue->update(['status' => VenueStatus::Rejected, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'rejection_reason' => $reason]);
        SystemNotification::send($venue->provider->owner, 'venue_rejected', ['venue' => $venue->name_ar, 'reason' => $reason], ['venue_id' => $venue->id]);
    }

    /** Hides the venue and stops new bookings. Bookings already paid stay valid. */
    public function suspend(Venue $venue, User $by, string $reason): void
    {
        $venue->update(['status' => VenueStatus::Suspended, 'rejection_reason' => $reason, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
        SystemNotification::send($venue->provider->owner, 'venue_suspended', ['venue' => $venue->name_ar, 'reason' => $reason], ['venue_id' => $venue->id]);
    }

    /** A provider may only offer activities in sectors (activity types) it is approved for. */
    public function assertSectorAllowed(Provider $provider, int $sectorId): void
    {
        if (! $provider->approvedSectors()->where('sectors.id', $sectorId)->exists()) {
            throw new BusinessException(__('api.venues.sector_not_allowed'), 'sector_not_allowed', 403);
        }
    }

    private function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'venue';

        return $base.'-'.Str::lower(Str::random(6));
    }
}
