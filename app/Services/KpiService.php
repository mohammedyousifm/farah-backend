<?php

namespace App\Services;

use App\Enums\ComplaintStatus;
use App\Enums\DocumentStatus;
use App\Enums\ProviderStatus;
use App\Enums\RefundStatus;
use App\Enums\RequestStatus;
use App\Enums\SettlementStatus;
use App\Enums\UserType;
use App\Enums\VenueStatus;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\Refund;
use App\Models\SectorRequest;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Headline numbers for the admin dashboard (date-range aware). */
class KpiService
{
    /** Booking statuses that count as sold play time (paid and not cancelled). */
    public const PLAYABLE = ['confirmed', 'checked_in', 'completed'];

    /** @return array{0: CarbonInterface, 1: CarbonInterface} */
    public static function range(?string $from = null, ?string $to = null): array
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : now()->subDays(29)->startOfDay();
        $end = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        return [$start, $end];
    }

    public function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $paid = Order::whereIn('status', Order::PAID_STATUSES)->whereBetween('confirmed_at', [$from, $to]);

        $gmv = (float) (clone $paid)->sum('total');
        $count = (clone $paid)->count();
        $refunded = (float) Refund::where('status', RefundStatus::Processed)->whereBetween('processed_at', [$from, $to])->sum('amount');

        $commission = (float) LedgerEntry::where('account', 'platform')
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        return [
            'gmv' => round($gmv, 2),
            'orders' => $count,
            'aov' => $count ? round($gmv / $count, 2) : 0,
            'commission' => round($commission, 2),
            'refunded' => round($refunded, 2),
            'refund_rate' => $gmv > 0 ? round($refunded / $gmv * 100, 1) : 0,
            'new_customers' => User::where('type', UserType::Customer)->whereBetween('created_at', [$from, $to])->count(),
            'active_providers' => Provider::where('status', ProviderStatus::Approved)->count(),
            'live_venues' => Venue::visible()->count(),
            'bookings_today' => $this->bookingsToday(),
        ];
    }

    /** Paid bookings that start today on the venues' clock (business timezone). Not range-aware. */
    public function bookingsToday(): int
    {
        $day = Carbon::now(config('farah.timezone', 'Asia/Riyadh'));

        return Booking::whereIn('status', self::PLAYABLE)
            ->whereBetween('starts_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])
            ->count();
    }

    /** Work-queue counts shown on the dashboard and as navigation badges. */
    public function queue(): array
    {
        // Memoized per request: the sidebar asks for one badge per resource.
        $key = 'farah.kpi.queue';
        if (app()->bound('request') && request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }

        $counts = [
            'providers' => Provider::where('status', ProviderStatus::Pending)->count(),
            'documents' => ProviderDocument::where('status', DocumentStatus::Pending)->count(),
            'venues' => Venue::where('status', VenueStatus::PendingReview)->count(),
            'activities' => Activity::where('review_status', Activity::PENDING)->count(),
            'sector_requests' => SectorRequest::where('status', RequestStatus::Pending)->count(),
            'refunds' => Refund::where('status', RefundStatus::Requested)->count(),
            'disputes' => Dispute::whereIn('status', ['open', 'under_review'])->count(),
            'complaints' => Complaint::whereIn('status', [ComplaintStatus::Open, ComplaintStatus::InProgress])->count(),
            'settlements' => Settlement::where('status', SettlementStatus::PendingApproval)->count(),
        ];

        if (app()->bound('request')) {
            request()->attributes->set($key, $counts);
        }

        return $counts;
    }

    /** Daily GMV and commission between two dates. */
    public function salesSeries(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = Order::whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('confirmed_at', [$from, $to])
            ->selectRaw('DATE(confirmed_at) as d, SUM(total) as gmv, SUM(commission_amount) as commission, COUNT(*) as orders')
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $labels = $gmv = $commission = $orders = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $labels[] = $day->format('m-d');
            $gmv[] = round((float) ($rows[$key]->gmv ?? 0), 2);
            $commission[] = round((float) ($rows[$key]->commission ?? 0), 2);
            $orders[] = (int) ($rows[$key]->orders ?? 0);
        }

        return compact('labels', 'gmv', 'commission', 'orders');
    }

    /**
     * Sold play time per activity type (sector): bookings of paid orders confirmed in the range.
     * An order can hold bookings of several activity types, so amounts are summed per booking.
     */
    public function ordersBySector(CarbonInterface $from, CarbonInterface $to): array
    {
        $column = app()->getLocale() === 'en' ? 'sectors.name_en' : 'sectors.name_ar';

        $rows = DB::table('bookings')
            ->join('orders', 'orders.id', '=', 'bookings.order_id')
            ->join('activities', 'activities.id', '=', 'bookings.activity_id')
            ->join('sectors', 'sectors.id', '=', 'activities.sector_id')
            ->whereIn('orders.status', Order::PAID_STATUSES)
            ->whereIn('bookings.status', self::PLAYABLE)
            ->whereBetween('orders.confirmed_at', [$from, $to])
            ->groupBy('sectors.id', $column)
            ->selectRaw("{$column} as label, COUNT(DISTINCT orders.id) as orders, COUNT(*) as bookings, SUM(bookings.total) as gmv")
            ->orderByDesc('gmv')
            ->get();

        return [
            'labels' => $rows->pluck('label')->all(),
            'orders' => $rows->pluck('orders')->map(fn ($v) => (int) $v)->all(),
            'bookings' => $rows->pluck('bookings')->map(fn ($v) => (int) $v)->all(),
            'gmv' => $rows->pluck('gmv')->map(fn ($v) => round((float) $v, 2))->all(),
        ];
    }
}
