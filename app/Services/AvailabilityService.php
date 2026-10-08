<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Activity;
use App\Models\BookingSlot;
use App\Models\Resource;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Time slots of a venue's resources.
 *
 * Working hours are wall-clock times in the business timezone (config farah.timezone);
 * everything stored and returned is UTC. A "business day" starts at opens_at and may
 * run past midnight, so 01:00 can belong to the previous day's window.
 */
class AvailabilityService
{
    public static function timezone(): string
    {
        return config('farah.timezone', 'Asia/Riyadh');
    }

    /**
     * Opening window of the venue for a local calendar date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null [open, close] in the business timezone
     */
    public function window(Venue $venue, CarbonInterface|string $date): ?array
    {
        $day = CarbonImmutable::parse(is_string($date) ? $date : $date->format('Y-m-d'), self::timezone())->startOfDay();
        $hours = $venue->hours->firstWhere('weekday', $day->dayOfWeek);

        if (! $hours || ! $hours->isOpen()) {
            return null;
        }

        $open = $day->setTimeFromTimeString($hours->opens_at);
        $close = $day->setTimeFromTimeString($hours->closes_at);
        if ($close->lessThanOrEqualTo($open)) {
            $close = $close->addDay();
        }

        return [$open, $close];
    }

    /**
     * Every slot of an activity on a date, per resource, with its state.
     *
     * @return array{date: string, timezone: string, is_open: bool, opens_at: ?string, closes_at: ?string,
     *               slot_minutes: int, slot_price: float, resources: array<int, array{resource: resource, slots: array<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, status: string}>}>}
     */
    public function forDate(Activity $activity, CarbonInterface|string $date): array
    {
        $venue = $activity->venue;
        $window = $this->window($venue, $date);
        $day = CarbonImmutable::parse(is_string($date) ? $date : $date->format('Y-m-d'), self::timezone())->startOfDay();
        $resources = $activity->resources->where('is_active', true)->values();

        $result = [
            'date' => $day->format('Y-m-d'),
            'timezone' => self::timezone(),
            'is_open' => $window !== null,
            'opens_at' => $window ? $window[0]->format('H:i') : null,
            'closes_at' => $window ? $window[1]->format('H:i') : null,
            'slot_minutes' => $activity->slot_minutes,
            'slot_price' => $activity->slotPrice(),
            'resources' => [],
        ];

        $starts = $window ? $this->slotStarts($activity, $window) : [];
        $lastDay = CarbonImmutable::now(self::timezone())->startOfDay()->addDays($venue->advance_booking_days);
        $tooFar = $day->greaterThan($lastDay);

        $taken = [];
        if ($starts !== [] && $resources->isNotEmpty()) {
            BookingSlot::whereIn('resource_id', $resources->pluck('id'))
                ->whereBetween('starts_at', [$starts[0]->utc(), end($starts)->utc()])
                ->get(['resource_id', 'starts_at'])
                ->each(function (BookingSlot $slot) use (&$taken) {
                    $taken[$slot->resource_id][$slot->starts_at->getTimestamp()] = true;
                });
        }

        $now = CarbonImmutable::now();

        foreach ($resources as $resource) {
            $slots = [];
            foreach ($starts as $start) {
                $status = match (true) {
                    $start->lessThanOrEqualTo($now) => 'past',
                    isset($taken[$resource->id][$start->getTimestamp()]) => 'booked',
                    $tooFar => 'closed',
                    default => 'available',
                };
                $slots[] = [
                    'starts_at' => $start->utc(),
                    'ends_at' => $start->addMinutes($activity->slot_minutes)->utc(),
                    'status' => $status,
                ];
            }
            $result['resources'][] = ['resource' => $resource, 'slots' => $slots];
        }

        return $result;
    }

    /**
     * Check a requested run of consecutive slots against the working hours and return the slot starts (UTC).
     *
     * @param  bool  $walkIn  provider booking for a customer at the counter: the first slot may already be running
     * @return array<int, CarbonImmutable>
     */
    public function assertRun(Activity $activity, CarbonInterface $startsAt, int $slots, bool $walkIn = false, bool $enforceRange = true): array
    {
        if ($slots < 1 || ($enforceRange && ($slots < $activity->min_slots || $slots > $activity->max_slots))) {
            throw new BusinessException(__('api.bookings.slots_range', ['min' => $activity->min_slots, 'max' => $activity->max_slots]), 'slots_range');
        }

        $venue = $activity->venue;
        $start = CarbonImmutable::instance($startsAt)->setTimezone(self::timezone());
        $end = $start->addMinutes($activity->slot_minutes * $slots);
        $now = CarbonImmutable::now(self::timezone());

        $earliest = $walkIn ? $now->subMinutes($activity->slot_minutes) : $now;
        if ($start->lessThanOrEqualTo($earliest)) {
            throw new BusinessException(__('api.bookings.in_the_past'), 'slot_in_past');
        }
        if (! $walkIn && $start->startOfDay()->greaterThan($now->startOfDay()->addDays($venue->advance_booking_days))) {
            throw new BusinessException(__('api.bookings.too_far', ['days' => $venue->advance_booking_days]), 'too_far_ahead');
        }

        // The start may belong to today's window or to yesterday's window running past midnight.
        foreach ([$start->startOfDay(), $start->startOfDay()->subDay()] as $day) {
            $window = $this->window($venue, $day);
            if (! $window || $start->lessThan($window[0]) || $end->greaterThan($window[1])) {
                continue;
            }
            if ($window[0]->diffInMinutes($start) % $activity->slot_minutes !== 0) {
                continue;
            }

            $starts = [];
            for ($i = 0; $i < $slots; $i++) {
                $starts[] = $start->addMinutes($activity->slot_minutes * $i)->utc();
            }

            return $starts;
        }

        throw new BusinessException(__('api.bookings.outside_hours'), 'outside_hours');
    }

    /** Provider takes slots out of sale (maintenance, private use). Returns how many were blocked. */
    public function block(Resource $resource, CarbonInterface $startsAt, int $slots, ?string $reason, User $by): int
    {
        $starts = $this->assertRun($resource->activity, $startsAt, $slots, true, false);

        try {
            return DB::transaction(function () use ($resource, $starts, $reason, $by) {
                foreach ($starts as $start) {
                    BookingSlot::create([
                        'resource_id' => $resource->id,
                        'starts_at' => $start,
                        'block_reason' => $reason ?: 'blocked',
                        'created_by' => $by->id,
                    ]);
                }

                return count($starts);
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessException(__('api.bookings.slot_taken'), 'slot_taken', 409);
        }
    }

    public function unblock(BookingSlot $slot): void
    {
        if ($slot->booking_id !== null) {
            throw new BusinessException(__('api.bookings.slot_taken'), 'slot_taken', 409);
        }

        $slot->delete();
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     * @return array<int, CarbonImmutable>
     */
    private function slotStarts(Activity $activity, array $window): array
    {
        $starts = [];
        for ($t = $window[0]; $t->addMinutes($activity->slot_minutes)->lessThanOrEqualTo($window[1]); $t = $t->addMinutes($activity->slot_minutes)) {
            $starts[] = $t;
        }

        return $starts;
    }
}
