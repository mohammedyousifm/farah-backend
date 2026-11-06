<?php

namespace App\Services\Reports;

use App\Services\AvailabilityService;
use App\Services\KpiService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** How much of the open hours of each venue was booked. Part of ReportService, which holds the shared helpers. */
trait OccupancyReport
{
    /**
     * Bookings and occupancy per venue, by play time (bookings that START in the range, on the
     * venue's clock). Occupancy = booked hours / (weekly opening hours over the range x active resources).
     * It uses today's working hours and resources, so it is an estimate for past periods.
     */
    public function venueOccupancy(CarbonInterface $from, CarbonInterface $to, array $f): array
    {
        $tz = AvailabilityService::timezone();
        $start = CarbonImmutable::instance($from)->setTimezone($tz)->startOfDay();
        $end = CarbonImmutable::instance($to)->setTimezone($tz)->endOfDay();

        $activityScope = fn (Builder $q) => $q
            ->when($f['sector_id'] ?? null, fn (Builder $q, $id) => $q->where('activities.sector_id', $id))
            ->when($f['category_id'] ?? null, fn (Builder $q, $id) => $q->where('activities.category_id', $id));

        $booked = $activityScope(DB::table('bookings')->join('activities', 'activities.id', '=', 'bookings.activity_id'))
            ->whereBetween('bookings.starts_at', [$start->utc(), $end->utc()])
            ->whereIn('bookings.status', [...KpiService::PLAYABLE, 'cancelled'])
            ->selectRaw("bookings.venue_id, SUM(bookings.status <> 'cancelled') as bookings, SUM(bookings.status = 'cancelled') as cancelled")
            ->selectRaw("SUM(CASE WHEN bookings.status <> 'cancelled' THEN bookings.slots * bookings.slot_minutes ELSE 0 END) / 60 as hours")
            ->selectRaw("SUM(CASE WHEN bookings.status <> 'cancelled' THEN bookings.total ELSE 0 END) as gross")
            ->selectRaw('SUM(bookings.checked_in_at IS NOT NULL) as checked_in')
            ->groupBy('bookings.venue_id');

        $resources = $activityScope(DB::table('resources')->join('activities', 'activities.id', '=', 'resources.activity_id'))
            ->where('resources.is_active', true)
            ->where('activities.is_active', true)
            ->selectRaw('activities.venue_id, COUNT(*) as resources')
            ->groupBy('activities.venue_id');

        $rows = DB::table('venues')
            ->whereNull('venues.deleted_at')
            ->join('providers', 'providers.id', '=', 'venues.provider_id')
            ->leftJoinSub($booked, 'b', 'b.venue_id', '=', 'venues.id')
            ->leftJoinSub($resources, 'r', 'r.venue_id', '=', 'venues.id')
            ->where(fn ($q) => $q->where('venues.status', 'published')->orWhereNotNull('b.venue_id'))
            ->when($f['provider_id'] ?? null, fn (Builder $q, $id) => $q->where('venues.provider_id', $id))
            ->when($f['venue_id'] ?? null, fn (Builder $q, $id) => $q->where('venues.id', $id))
            ->when(($f['sector_id'] ?? null) || ($f['category_id'] ?? null), fn (Builder $q) => $q->whereNotNull('r.venue_id'))
            ->selectRaw('venues.id, '.$this->name('venues', 'name').' as venue, '.$this->name('providers', 'name').' as provider')
            ->selectRaw('COALESCE(r.resources, 0) as resources, COALESCE(b.bookings, 0) as bookings, COALESCE(b.cancelled, 0) as cancelled, COALESCE(b.hours, 0) as hours, COALESCE(b.gross, 0) as gross, COALESCE(b.checked_in, 0) as checked_in')
            ->orderByDesc('hours')
            ->get();

        $open = $this->openHours($rows->pluck('id')->all(), $start, $end);

        return $this->result(
            [
                'venue' => 'venue', 'provider' => 'provider', 'resources' => 'resources', 'bookings' => 'bookings', 'cancelled' => 'cancelled_bookings',
                'checked_in' => 'checked_in', 'hours' => 'booked_hours', 'available' => 'available_hours', 'occupancy' => 'occupancy', 'gross' => 'gross_sales',
            ],
            $rows->map(function ($r) use ($open) {
                $available = ($open[$r->id] ?? 0) * (int) $r->resources;

                return [
                    'venue' => $r->venue,
                    'provider' => $r->provider,
                    'resources' => (int) $r->resources,
                    'bookings' => (int) $r->bookings,
                    'cancelled' => (int) $r->cancelled,
                    'checked_in' => (int) $r->checked_in,
                    'hours' => round((float) $r->hours, 1),
                    'available' => round($available, 1),
                    'occupancy' => $available > 0 ? round($r->hours / $available * 100, 1).'%' : '-',
                    'gross' => $this->money($r->gross),
                ];
            }),
            ['gross'],
            ['bookings', 'cancelled', 'checked_in', 'hours', 'available', 'gross'],
        );
    }

    /**
     * Opening hours of each venue over a range of days (per single resource).
     *
     * @param  array<int, int>  $venueIds
     * @return array<int, float> venue id => hours
     */
    private function openHours(array $venueIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        // How many times each weekday (0 = Sunday) occurs in the range.
        $days = array_fill(0, 7, 0);
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $days[$day->dayOfWeek]++;
        }

        $hours = [];
        foreach (DB::table('venue_hours')->whereIn('venue_id', $venueIds)->where('is_closed', false)->get() as $h) {
            if ($h->opens_at === null || $h->closes_at === null) {
                continue;
            }
            $minutes = (strtotime($h->closes_at) - strtotime($h->opens_at)) / 60;
            // closes_at <= opens_at means the day runs past midnight.
            $minutes += $minutes <= 0 ? 1440 : 0;
            $hours[$h->venue_id] = ($hours[$h->venue_id] ?? 0) + $minutes / 60 * $days[(int) $h->weekday];
        }

        return $hours;
    }

    // ------------------------------------------------------------------ RPT-06
}
