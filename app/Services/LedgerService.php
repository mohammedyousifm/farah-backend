<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Provider;
use App\Models\Refund;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Writes the double-sided financial record for every money movement.
 *
 * Provider account (what the platform owes the provider):
 *   sale +gross, commission -fee, refund -amount, commission_reversal +fee back,
 *   payout -net (when a settlement is paid), adjustment ±.
 * Platform account (platform revenue): commission +fee, commission_reversal -fee.
 *
 * Offline orders are paid to the provider directly, so only the commission
 * the provider owes is recorded; it is deducted from the next settlement.
 */
class LedgerService
{
    public function recordOrder(Order $order): void
    {
        if ($order->ledgerEntries()->exists()) {
            return; // idempotent
        }

        $availableAt = $this->availableAt($order);
        $base = ['provider_id' => $order->provider_id, 'order_id' => $order->id, 'available_at' => $availableAt];

        if ($order->channel === 'online') {
            $this->entry('provider', LedgerType::Sale, $order->total, __('ledger.sale', ['number' => $order->number]), $base);
        }

        if ($order->commission_amount > 0) {
            $this->entry('provider', LedgerType::Commission, -$order->commission_amount, __('ledger.commission', ['number' => $order->number]), $base);
            $this->entry('platform', LedgerType::Commission, $order->commission_amount, __('ledger.commission', ['number' => $order->number]), $base);
        }
    }

    public function recordRefund(Refund $refund): void
    {
        if (LedgerEntry::where('refund_id', $refund->id)->exists()) {
            return; // idempotent
        }

        $order = $refund->order;
        $base = [
            'provider_id' => $order->provider_id,
            'order_id' => $order->id,
            'refund_id' => $refund->id,
            'available_at' => now(),
        ];

        if ($order->channel === 'online' && $refund->amount > 0) {
            $this->entry('provider', LedgerType::Refund, -$refund->amount, __('ledger.refund', ['number' => $refund->number]), $base);
        }

        if ($refund->commission_reversed > 0) {
            $this->entry('provider', LedgerType::CommissionReversal, $refund->commission_reversed, __('ledger.commission_reversal', ['number' => $refund->number]), $base);
            $this->entry('platform', LedgerType::CommissionReversal, -$refund->commission_reversed, __('ledger.commission_reversal', ['number' => $refund->number]), $base);
        }
    }

    public function adjustment(Provider $provider, float $amount, string $description): LedgerEntry
    {
        return $this->entry('provider', LedgerType::Adjustment, $amount, $description, [
            'provider_id' => $provider->id,
            'available_at' => now(),
        ]);
    }

    /**
     * @return array{pending: float, available: float, in_settlement: float, paid_out: float, lifetime_sales: float, lifetime_commission: float}
     */
    public function balances(Provider $provider): array
    {
        $q = fn () => LedgerEntry::where('account', 'provider')->where('provider_id', $provider->id);

        return [
            // earned but still inside the hold period
            'pending' => round((float) $q()->whereNull('settlement_id')->where('available_at', '>', now())->sum('amount'), 2),
            // free to be included in the next settlement
            'available' => round((float) $q()->whereNull('settlement_id')->where('available_at', '<=', now())->sum('amount'), 2),
            // attached to a settlement that is not paid yet
            'in_settlement' => round((float) $q()->whereNotNull('settlement_id')->where('type', '!=', LedgerType::Payout)
                ->whereHas('settlement', fn ($s) => $s->whereIn('status', ['draft', 'pending_approval', 'approved']))->sum('amount'), 2),
            // "+ 0.0" normalises -0.0 to 0.0 for display
            'paid_out' => round(-(float) $q()->where('type', LedgerType::Payout)->sum('amount'), 2) + 0.0,
            'lifetime_sales' => round((float) $q()->where('type', LedgerType::Sale)->sum('amount'), 2),
            'lifetime_commission' => round(-(float) $q()->whereIn('type', [LedgerType::Commission, LedgerType::CommissionReversal])->sum('amount'), 2) + 0.0,
        ];
    }

    public function entry(string $account, LedgerType $type, float $amount, string $description, array $attributes = []): LedgerEntry
    {
        return LedgerEntry::create(array_merge($attributes, [
            'account' => $account,
            'type' => $type,
            'amount' => round($amount, 2),
            'description' => $description,
            'created_by' => auth()->id(),
        ]));
    }

    /** Funds are held until the last booked slot is over plus the configured hold days (BR-006). */
    private function availableAt(Order $order): CarbonInterface
    {
        $end = $order->bookings()->max('ends_at');
        $end = $end ? Carbon::parse($end) : now();
        $base = $end->isFuture() ? $end : now();

        return $base->copy()->addDays((int) Setting::get('settlement_hold_days'));
    }
}
