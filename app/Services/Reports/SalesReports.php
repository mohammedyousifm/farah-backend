<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Services\KpiService;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Sales by period, provider, venue and activity type. Part of ReportService, which holds the shared helpers. */
trait SalesReports
{
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
}
