<?php

namespace App\Services;

use App\Models\Order;
use App\Services\Reports\FinanceReports;
use App\Services\Reports\OccupancyReport;
use App\Services\Reports\PeopleReports;
use App\Services\Reports\SalesReports;
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
    use FinanceReports;
    use OccupancyReport;
    use PeopleReports;
    use SalesReports;

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
