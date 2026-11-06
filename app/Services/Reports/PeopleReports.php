<?php

namespace App\Services\Reports;

use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Customers, providers, reviews and complaints. Part of ReportService, which holds the shared helpers. */
trait PeopleReports
{
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
}
