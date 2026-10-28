<?php

namespace App\Services;

use App\Enums\DisputeStatus;
use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Enums\RefundStatus;
use App\Enums\SettlementStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Admin reports RPT-01..11. Every report returns the same shape so the
 * Reports page and the Excel / CSV / PDF exports can render any of them:
 *
 *   columns: [key => translated label]
 *   money:   list of column keys holding SAR amounts
 *   rows:    list of [key => value]
 *   totals:  [key => value|null] or null when nothing is summable
 */
class ReportService
{
    public const REPORTS = [
        'sales_by_period', 'sales_by_provider', 'sales_by_venue', 'sales_by_sector', 'venue_occupancy', 'commissions',
        'settlements', 'refunds_disputes', 'customers', 'providers', 'reviews_complaints',
    ];

    /** @return array<string, string> key => "RPT-01 · title" */
    public function reports(): array
    {
        return collect(self::REPORTS)->mapWithKeys(fn (string $key, int $i) => [
            $key => sprintf('RPT-%02d · %s', $i + 1, __("admin.reports.{$key}")),
        ])->all();
    }

    public function title(string $key): string
    {
        return $this->reports()[$key] ?? $key;
    }

    public function run(string $key, array $filters = []): array
    {
        if (! in_array($key, self::REPORTS, true)) {
            throw new InvalidArgumentException("Unknown report [{$key}]");
        }

        // Whole days on the business clock, as UTC instants to compare with the timestamp columns.
        [$from, $to] = KpiService::utc(...KpiService::range($filters['from'] ?? null, $filters['to'] ?? null));

        return $this->{str($key)->camel()->toString()}($from, $to, $filters);
    }

    // ------------------------------------------------------------------ RPT-01

    public function salesByPeriod(CarbonInterface $from, CarbonInterface $to, array $f): array
    {
        // Periods follow the business clock, not UTC.
        $day = KpiService::localDate('orders.confirmed_at');
        $expr = match ($f['group_by'] ?? 'day') {
            'month' => "DATE_FORMAT({$day}, '%Y-%m')",
            'week' => "DATE_FORMAT({$day}, '%x-W%v')",
            default => $day,
        };

        $rows = $this->paidOrders($from, $to, $f)
            ->selectRaw("{$expr} as period")
            ->selectRaw($this->salesMetrics())
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return $this->result(
            ['period' => 'period', ...$this->salesColumns()],
            $rows->map(fn ($r) => ['period' => (string) $r->period] + $this->salesRow($r)),
            $this->moneyKeys(),
        );
    }

    // ------------------------------------------------------------------ RPT-02

    public function salesByProvider(CarbonInterface $from, CarbonInterface $to, array $f): array
    {
        $rows = $this->paidOrders($from, $to, $f)
            ->join('providers', 'providers.id', '=', 'orders.provider_id')
            ->selectRaw($this->name('providers', 'name').' as provider')
            ->selectRaw($this->salesMetrics())
            ->groupBy('providers.id', 'providers.name_ar', 'providers.name_en')
            ->orderByDesc('gross')
            ->get();

        return $this->result(
            ['provider' => 'provider', ...$this->salesColumns()],
            $rows->map(fn ($r) => ['provider' => $r->provider] + $this->salesRow($r)),
            $this->moneyKeys(),
        );
    }

    // ------------------------------------------------------------------ RPT-03

    public function salesByVenue(CarbonInterface $from, CarbonInterface $to, array $f): array
    {
        $rows = $this->paidOrders($from, $to, $f)
            ->join('venues', 'venues.id', '=', 'orders.venue_id')
            ->join('providers', 'providers.id', '=', 'orders.provider_id')
            ->leftJoin('cities', 'cities.id', '=', 'venues.city_id')
            ->selectRaw($this->name('venues', 'name').' as venue, '.$this->name('providers', 'name').' as provider, '.$this->name('cities', 'name').' as city')
            ->selectRaw($this->salesMetrics())
            ->groupBy('venues.id', 'venues.name_ar', 'venues.name_en', 'providers.name_ar', 'providers.name_en', 'cities.name_ar', 'cities.name_en')
            ->orderByDesc('gross')
            ->get();

        return $this->result(
            ['venue' => 'venue', 'provider' => 'provider', 'city' => 'city', ...$this->salesColumns()],
            $rows->map(fn ($r) => [
                'venue' => $r->venue,
                'provider' => $r->provider,
                'city' => $r->city ?? '-',
            ] + $this->salesRow($r)),
            $this->moneyKeys(),
        );
    }

    // ------------------------------------------------------------------ RPT-04

    /**
     * Sold play time per activity type (sector) and category. An order can hold bookings of
     * several activity types, so this report sums booking amounts, not order amounts.
     */
    public function salesBySector(CarbonInterface $from, CarbonInterface $to, array $f): array
    {
        $rows = DB::table('bookings')
            ->join('orders', 'orders.id', '=', 'bookings.order_id')
            ->join('activities', 'activities.id', '=', 'bookings.activity_id')
            ->join('sectors', 'sectors.id', '=', 'activities.sector_id')
            ->leftJoin('categories', 'categories.id', '=', 'activities.category_id')
            ->whereIn('orders.status', Order::PAID_STATUSES)
            ->whereIn('bookings.status', KpiService::PLAYABLE)
            ->whereBetween('orders.confirmed_at', [$from, $to])
            ->when($f['provider_id'] ?? null, fn (Builder $q, $id) => $q->where('orders.provider_id', $id))
            ->when($f['venue_id'] ?? null, fn (Builder $q, $id) => $q->where('orders.venue_id', $id))
            ->when($f['sector_id'] ?? null, fn (Builder $q, $id) => $q->where('activities.sector_id', $id))
            ->when($f['category_id'] ?? null, fn (Builder $q, $id) => $q->where('activities.category_id', $id))
            ->selectRaw($this->name('sectors', 'name').' as sector, '.$this->name('categories', 'name').' as category')
            ->selectRaw('COUNT(DISTINCT orders.id) as orders, COUNT(*) as bookings, SUM(bookings.slots * bookings.slot_minutes) / 60 as hours, SUM(bookings.total) as gross')
            ->groupBy('sectors.id', 'sectors.name_ar', 'sectors.name_en', 'categories.id', 'categories.name_ar', 'categories.name_en')
            ->orderBy('sector')
            ->orderByDesc('gross')
            ->get();

        return $this->result(
            ['sector' => 'sector', 'category' => 'category', 'orders' => 'orders', 'bookings' => 'bookings', 'hours' => 'hours', 'gross' => 'gross_sales', 'avg_hour' => 'avg_hour_price'],
            $rows->map(fn ($r) => [
                'sector' => $r->sector,
                'category' => $r->category ?? '-',
                'orders' => (int) $r->orders,
                'bookings' => (int) $r->bookings,
                'hours' => round((float) $r->hours, 1),
                'gross' => $this->money($r->gross),
                'avg_hour' => $r->hours > 0 ? $this->money($r->gross / $r->hours) : 0.0,
            ]),
            ['gross', 'avg_hour'],
            ['bookings', 'hours', 'gross'],
        );
    }

    // ------------------------------------------------------------------ RPT-05

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

    /** Platform commission from the ledger (earned minus reversed on refunds), per provider. */
    public function commissions(CarbonInterface $from, CarbonInterface $to, array $f): array
    {
        $rows = DB::table('ledger_entries')
            ->join('providers', 'providers.id', '=', 'ledger_entries.provider_id')
            ->leftJoin('orders', 'orders.id', '=', 'ledger_entries.order_id')
            ->where('ledger_entries.account', 'platform')
            ->whereBetween('ledger_entries.created_at', [$from, $to])
            ->when($f['provider_id'] ?? null, fn (Builder $q, $id) => $q->where('ledger_entries.provider_id', $id))
            ->when($f['venue_id'] ?? null, fn (Builder $q, $id) => $q->where('orders.venue_id', $id))
            ->tap(fn (Builder $q) => $this->activityFilter($q, $f))
            ->selectRaw($this->name('providers', 'name').' as provider')
            ->selectRaw("COUNT(DISTINCT CASE WHEN ledger_entries.type = 'commission' THEN ledger_entries.order_id END) as orders")
            ->selectRaw("SUM(CASE WHEN ledger_entries.type = 'commission' THEN ledger_entries.amount ELSE 0 END) as earned")
            ->selectRaw("-SUM(CASE WHEN ledger_entries.type = 'commission_reversal' THEN ledger_entries.amount ELSE 0 END) as reversed")
            ->selectRaw("SUM(CASE WHEN ledger_entries.type = 'adjustment' THEN ledger_entries.amount ELSE 0 END) as adjustments")
            ->selectRaw('SUM(ledger_entries.amount) as net')
            ->groupBy('providers.id', 'providers.name_ar', 'providers.name_en')
            ->orderByDesc('net')
            ->get();

        return $this->result(
            ['provider' => 'provider', 'orders' => 'orders', 'earned' => 'commission_earned', 'reversed' => 'commission_reversed', 'adjustments' => 'adjustments', 'net' => 'net_commission'],
            $rows->map(fn ($r) => [
                'provider' => $r->provider,
                'orders' => (int) $r->orders,
                'earned' => $this->money($r->earned),
                'reversed' => $this->money($r->reversed),
                'adjustments' => $this->money($r->adjustments),
                'net' => $this->money($r->net),
            ]),
            ['earned', 'reversed', 'adjustments', 'net'],
            ['orders', 'earned', 'reversed', 'adjustments', 'net'],
        );
    }

    // ------------------------------------------------------------------ RPT-07

    public function settlements(CarbonInterface $from, CarbonInterface $to, array $f): array
    {
        $rows = DB::table('settlements')
            ->join('providers', 'providers.id', '=', 'settlements.provider_id')
            ->whereBetween('settlements.created_at', [$from, $to])
            ->when($f['provider_id'] ?? null, fn (Builder $q, $id) => $q->where('settlements.provider_id', $id))
            ->when($f['sector_id'] ?? null, fn (Builder $q, $id) => $q->whereExists(fn ($s) => $s->from('provider_sector')
                ->whereColumn('provider_sector.provider_id', 'settlements.provider_id')->where('provider_sector.sector_id', $id)))
            ->selectRaw('settlements.*, '.$this->name('providers', 'name').' as provider')
            ->orderByDesc('settlements.created_at')
            ->get();

        return $this->result(
            [
                'number' => 'number', 'provider' => 'provider', 'period' => 'period', 'gross' => 'gross_sales',
                'commission' => 'commission', 'refunds' => 'refunds', 'adjustments' => 'adjustments',
                'net' => 'net_payout', 'status' => 'status', 'paid_at' => 'paid_at', 'reference' => 'payment_reference',
            ],
            $rows->map(fn ($r) => [
                'number' => $r->number,
                'provider' => $r->provider,
                'period' => $r->period_from.' → '.$r->period_to,
                'gross' => $this->money($r->gross_amount),
                'commission' => $this->money($r->commission_amount),
                'refunds' => $this->money($r->refunds_amount),
                'adjustments' => $this->money($r->adjustments_amount),
                'net' => $this->money($r->net_amount),
                'status' => SettlementStatus::tryFrom($r->status)?->getLabel() ?? $r->status,
                'paid_at' => $this->local($r->paid_at),
                'reference' => $r->payment_reference ?? '-',
            ]),
            ['gross', 'commission', 'refunds', 'adjustments', 'net'],
        );
    }

    // ------------------------------------------------------------------ RPT-08

    public function refundsDisputes(CarbonInterface $from, CarbonInterface $to, array $f): array
    {
        $scope = fn (Builder $q, string $table) => $q
            ->join('orders', 'orders.id', '=', "{$table}.order_id")
            ->join('venues', 'venues.id', '=', 'orders.venue_id')
            ->join('providers', 'providers.id', '=', 'orders.provider_id')
            ->whereBetween("{$table}.created_at", [$from, $to])
            ->when($f['provider_id'] ?? null, fn (Builder $q, $id) => $q->where('orders.provider_id', $id))
            ->when($f['venue_id'] ?? null, fn (Builder $q, $id) => $q->where('orders.venue_id', $id))
            ->tap(fn (Builder $q) => $this->activityFilter($q, $f));

        $refunds = $scope(DB::table('refunds'), 'refunds')
            ->selectRaw('refunds.number, refunds.amount, refunds.status, refunds.reason, refunds.created_at, orders.number as order_number, '.$this->name('venues', 'name').' as venue, '.$this->name('providers', 'name').' as provider')
            ->get()
            ->map(fn ($r) => [
                'kind' => __('admin.reports.kind_refund'),
                'number' => $r->number,
                'order' => $r->order_number,
                'venue' => $r->venue,
                'provider' => $r->provider,
                'amount' => $this->money($r->amount),
                'status' => RefundStatus::tryFrom($r->status)?->getLabel() ?? $r->status,
                'reason' => (string) $r->reason,
                'date' => $this->local($r->created_at),
            ]);

        $disputes = $scope(DB::table('disputes'), 'disputes')
            ->selectRaw('disputes.number, disputes.amount, disputes.status, disputes.reason, disputes.created_at, orders.number as order_number, '.$this->name('venues', 'name').' as venue, '.$this->name('providers', 'name').' as provider')
            ->get()
            ->map(fn ($r) => [
                'kind' => __('admin.reports.kind_dispute'),
                'number' => $r->number,
                'order' => $r->order_number,
                'venue' => $r->venue,
                'provider' => $r->provider,
                'amount' => $this->money($r->amount),
                'status' => DisputeStatus::tryFrom($r->status)?->getLabel() ?? $r->status,
                'reason' => (string) $r->reason,
                'date' => $this->local($r->created_at),
            ]);

        return $this->result(
            ['kind' => 'type', 'number' => 'number', 'order' => 'order', 'venue' => 'venue', 'provider' => 'provider', 'amount' => 'amount', 'status' => 'status', 'reason' => 'reason', 'date' => 'date'],
            $refunds->concat($disputes)->sortByDesc('date')->values(),
            ['amount'],
        );
    }

    // ------------------------------------------------------------------ RPT-09

    public function customers(CarbonInterface $from, CarbonInterface $to, array $f): array
    {
        $rows = $this->paidOrders($from, $to, $f)
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->selectRaw('users.name, users.phone, users.email, users.created_at as registered_at')
            ->selectRaw('COUNT(orders.id) as orders, SUM(orders.total) as spent, SUM(orders.refunded_amount) as refunded, MAX(orders.confirmed_at) as last_order')
            ->groupBy('users.id', 'users.name', 'users.phone', 'users.email', 'users.created_at')
            ->orderByDesc('spent')
            ->get();

        return $this->result(
            ['name' => 'customer', 'contact' => 'contact', 'orders' => 'orders', 'spent' => 'total_spent', 'refunded' => 'refunded', 'last_order' => 'last_order', 'registered_at' => 'registered_at'],
            $rows->map(fn ($r) => [
                'name' => (string) $r->name,
                'contact' => $r->phone ?: ($r->email ?: '-'),
                'orders' => (int) $r->orders,
                'spent' => $this->money($r->spent),
                'refunded' => $this->money($r->refunded),
                'last_order' => $this->local($r->last_order),
                'registered_at' => $this->local($r->registered_at, true),
            ]),
            ['spent', 'refunded'],
            ['orders', 'spent', 'refunded'],
        );
    }

    // ------------------------------------------------------------------ RPT-10

    public function providers(CarbonInterface $from, CarbonInterface $to, array $f): array
    {
        $sales = DB::table('orders')
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('confirmed_at', [$from, $to])
            ->selectRaw('provider_id, COUNT(*) as orders, SUM(total) as gross, SUM(commission_amount) as commission')
            ->groupBy('provider_id');

        $venues = DB::table('venues')
            ->whereNull('deleted_at')
            ->selectRaw("provider_id, COUNT(*) as venues, SUM(status = 'published') as published")
            ->groupBy('provider_id');

        $rows = DB::table('providers')
            ->whereNull('providers.deleted_at')
            ->leftJoin('cities', 'cities.id', '=', 'providers.city_id')
            ->leftJoinSub($sales, 's', 's.provider_id', '=', 'providers.id')
            ->leftJoinSub($venues, 'v', 'v.provider_id', '=', 'providers.id')
            ->when($f['provider_id'] ?? null, fn (Builder $q, $id) => $q->where('providers.id', $id))
            ->when($f['sector_id'] ?? null, fn (Builder $q, $id) => $q->whereExists(fn ($s) => $s->from('provider_sector')
                ->whereColumn('provider_sector.provider_id', 'providers.id')->where('provider_sector.sector_id', $id)))
            ->selectRaw($this->name('providers', 'name').' as provider, providers.type, providers.status, providers.rating_avg, providers.rating_count, providers.created_at')
            ->selectRaw($this->name('cities', 'name').' as city')
            ->selectRaw('COALESCE(v.venues, 0) as venues, COALESCE(v.published, 0) as published, COALESCE(s.orders, 0) as orders, COALESCE(s.gross, 0) as gross, COALESCE(s.commission, 0) as commission')
            ->orderByDesc('gross')
            ->get();

        return $this->result(
            [
                'provider' => 'provider', 'type' => 'type', 'status' => 'status', 'city' => 'city', 'venues' => 'venues',
                'published' => 'published_venues', 'orders' => 'orders', 'gross' => 'gross_sales', 'commission' => 'commission',
                'rating' => 'rating', 'joined_at' => 'joined_at',
            ],
            $rows->map(fn ($r) => [
                'provider' => $r->provider,
                'type' => ProviderType::tryFrom($r->type)?->getLabel() ?? $r->type,
                'status' => ProviderStatus::tryFrom($r->status)?->getLabel() ?? $r->status,
                'city' => $r->city ?? '-',
                'venues' => (int) $r->venues,
                'published' => (int) $r->published,
                'orders' => (int) $r->orders,
                'gross' => $this->money($r->gross),
                'commission' => $this->money($r->commission),
                'rating' => number_format((float) $r->rating_avg, 1).' ('.$r->rating_count.')',
                'joined_at' => $this->local($r->created_at, true),
            ]),
            ['gross', 'commission'],
            ['venues', 'published', 'orders', 'gross', 'commission'],
        );
    }

    // ------------------------------------------------------------------ RPT-11

    public function reviewsComplaints(CarbonInterface $from, CarbonInterface $to, array $f): array
    {
        $reviews = DB::table('reviews')
            ->whereBetween('reviews.created_at', [$from, $to])
            ->when($f['venue_id'] ?? null, fn (Builder $q, $id) => $q->where('reviews.venue_id', $id))
            ->when(($f['sector_id'] ?? null) || ($f['category_id'] ?? null), fn (Builder $q) => $q->whereExists(fn ($a) => $a->from('activities')
                ->whereColumn('activities.venue_id', 'reviews.venue_id')
                ->when($f['sector_id'] ?? null, fn ($a, $id) => $a->where('activities.sector_id', $id))
                ->when($f['category_id'] ?? null, fn ($a, $id) => $a->where('activities.category_id', $id))))
            ->selectRaw("reviews.provider_id, COUNT(*) as reviews, AVG(reviews.rating) as avg_rating, SUM(reviews.status = 'hidden') as hidden, SUM(reviews.rating <= 2) as low")
            ->groupBy('reviews.provider_id');

        $complaints = DB::table('complaints')
            ->whereNotNull('provider_id')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("provider_id, COUNT(*) as complaints, SUM(status IN ('open', 'in_progress')) as open_complaints, SUM(status IN ('resolved', 'closed')) as resolved")
            ->groupBy('provider_id');

        $rows = DB::table('providers')
            ->leftJoinSub($reviews, 'r', 'r.provider_id', '=', 'providers.id')
            ->leftJoinSub($complaints, 'c', 'c.provider_id', '=', 'providers.id')
            ->where(fn ($q) => $q->whereNotNull('r.provider_id')->orWhereNotNull('c.provider_id'))
            ->when($f['provider_id'] ?? null, fn (Builder $q, $id) => $q->where('providers.id', $id))
            ->selectRaw($this->name('providers', 'name').' as provider')
            ->selectRaw('COALESCE(r.reviews, 0) as reviews, r.avg_rating, COALESCE(r.hidden, 0) as hidden, COALESCE(r.low, 0) as low')
            ->selectRaw('COALESCE(c.complaints, 0) as complaints, COALESCE(c.open_complaints, 0) as open_complaints, COALESCE(c.resolved, 0) as resolved')
            ->orderByDesc('complaints')
            ->orderByDesc('reviews')
            ->get();

        return $this->result(
            [
                'provider' => 'provider', 'reviews' => 'reviews', 'avg_rating' => 'avg_rating', 'low' => 'low_ratings',
                'hidden' => 'hidden_reviews', 'complaints' => 'complaints', 'open' => 'open_complaints', 'resolved' => 'resolved_complaints',
            ],
            $rows->map(fn ($r) => [
                'provider' => $r->provider,
                'reviews' => (int) $r->reviews,
                'avg_rating' => $r->avg_rating !== null ? round((float) $r->avg_rating, 2) : '-',
                'low' => (int) $r->low,
                'hidden' => (int) $r->hidden,
                'complaints' => (int) $r->complaints,
                'open' => (int) $r->open_complaints,
                'resolved' => (int) $r->resolved,
            ]),
            [],
            ['reviews', 'low', 'hidden', 'complaints', 'open', 'resolved'],
        );
    }

    // ------------------------------------------------------------------ helpers

    /** Paid orders confirmed in the range with their booking counts, and the common filters applied. */
    private function paidOrders(CarbonInterface $from, CarbonInterface $to, array $f): Builder
    {
        $bookings = DB::table('bookings')
            ->whereIn('status', KpiService::PLAYABLE)
            ->selectRaw('order_id, COUNT(*) as qty, SUM(slots * slot_minutes) / 60 as hours')
            ->groupBy('order_id');

        return DB::table('orders')
            ->leftJoinSub($bookings, 'ob', 'ob.order_id', '=', 'orders.id')
            ->whereIn('orders.status', Order::PAID_STATUSES)
            ->whereBetween('orders.confirmed_at', [$from, $to])
            ->when($f['provider_id'] ?? null, fn (Builder $q, $id) => $q->where('orders.provider_id', $id))
            ->when($f['venue_id'] ?? null, fn (Builder $q, $id) => $q->where('orders.venue_id', $id))
            ->tap(fn (Builder $q) => $this->activityFilter($q, $f));
    }

    /** Sector (activity type) / category filters: keep orders that hold a booking of a matching activity. */
    private function activityFilter(Builder $query, array $f): void
    {
        if (! ($f['sector_id'] ?? null) && ! ($f['category_id'] ?? null)) {
            return;
        }

        $query->whereExists(fn ($q) => $q->from('bookings')
            ->join('activities', 'activities.id', '=', 'bookings.activity_id')
            ->whereColumn('bookings.order_id', 'orders.id')
            ->when($f['sector_id'] ?? null, fn ($q, $id) => $q->where('activities.sector_id', $id))
            ->when($f['category_id'] ?? null, fn ($q, $id) => $q->where('activities.category_id', $id)));
    }

    private function salesMetrics(): string
    {
        return 'COUNT(orders.id) as orders, COALESCE(SUM(ob.qty), 0) as bookings, COALESCE(SUM(ob.hours), 0) as hours, SUM(orders.total) as gross, '
            .'SUM(orders.refunded_amount) as refunded, SUM(orders.vat_amount) as vat, '
            .'SUM(orders.commission_amount) as commission, SUM(orders.provider_net) as provider_net';
    }

    private function salesColumns(): array
    {
        return [
            'orders' => 'orders', 'bookings' => 'bookings', 'hours' => 'hours', 'gross' => 'gross_sales', 'refunded' => 'refunded',
            'net_sales' => 'net_sales', 'vat' => 'vat', 'commission' => 'commission', 'provider_net' => 'provider_net',
        ];
    }

    private function moneyKeys(): array
    {
        return ['gross', 'refunded', 'net_sales', 'vat', 'commission', 'provider_net'];
    }

    private function salesRow(object $r): array
    {
        return [
            'orders' => (int) $r->orders,
            'bookings' => (int) $r->bookings,
            'hours' => round((float) $r->hours, 1),
            'gross' => $this->money($r->gross),
            'refunded' => $this->money($r->refunded),
            'net_sales' => $this->money($r->gross - $r->refunded),
            'vat' => $this->money($r->vat),
            'commission' => $this->money($r->commission),
            'provider_net' => $this->money($r->provider_net),
        ];
    }

    /** SQL expression for a bilingual column in the current locale, falling back to Arabic. */
    private function name(string $table, string $field): string
    {
        return app()->getLocale() === 'en'
            ? "COALESCE(NULLIF({$table}.{$field}_en, ''), {$table}.{$field}_ar)"
            : "{$table}.{$field}_ar";
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2) + 0.0;
    }

    /** A UTC timestamp from the database on the business clock ("Y-m-d H:i", or the date only); "-" when empty. */
    private function local(?string $value, bool $dateOnly = false): string
    {
        if (! $value) {
            return '-';
        }

        return CarbonImmutable::parse($value, 'UTC')->setTimezone(AvailabilityService::timezone())->format($dateOnly ? 'Y-m-d' : 'Y-m-d H:i');
    }

    /**
     * @param  array<string, string>  $columns  key => label key under admin.reports.columns
     * @param  iterable<array>  $rows
     * @param  array<int, string>  $money  money column keys
     * @param  array<int, string>|null  $sum  keys to total (defaults to the money keys + orders/bookings/hours when present)
     */
    private function result(array $columns, iterable $rows, array $money, ?array $sum = null): array
    {
        $rows = collect($rows)->values()->all();
        $sum ??= array_values(array_intersect(array_keys($columns), [...$money, 'orders', 'bookings', 'hours']));

        $totals = null;
        if ($sum !== [] && $rows !== []) {
            $totals = [];
            foreach (array_keys($columns) as $i => $key) {
                $totals[$key] = in_array($key, $sum, true)
                    ? (in_array($key, $money, true) ? $this->money(array_sum(array_column($rows, $key))) : array_sum(array_column($rows, $key)))
                    : ($i === 0 ? __('admin.reports.total') : null);
            }
        }

        return [
            'columns' => collect($columns)->map(fn (string $label) => __("admin.reports.columns.{$label}"))->all(),
            'money' => $money,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }
}
