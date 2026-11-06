<?php

namespace App\Services\Reports;

use App\Enums\DisputeStatus;
use App\Enums\RefundStatus;
use App\Enums\SettlementStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Commissions, settlements, refunds and disputes. Part of ReportService, which holds the shared helpers. */
trait FinanceReports
{
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
}
