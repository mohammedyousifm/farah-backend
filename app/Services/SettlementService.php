<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Enums\ProviderStatus;
use App\Enums\SettlementStatus;
use App\Exceptions\BusinessException;
use App\Models\LedgerEntry;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\DocNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Settlement cycle (BR-005): available ledger entries are grouped into a
 * statement → approved by finance → paid (bank transfer) → payout entry.
 */
class SettlementService
{
    public function __construct(private LedgerService $ledger) {}

    public function generate(Provider $provider, CarbonInterface $until, ?User $by = null, bool $enforceMinimum = true): Settlement
    {
        return DB::transaction(function () use ($provider, $until, $by, $enforceMinimum) {
            // One settlement run per provider at a time (scheduler + admin button cannot overlap).
            $provider = Provider::withTrashed()->whereKey($provider->id)->lockForUpdate()->firstOrFail();

            $entries = LedgerEntry::where('account', 'provider')
                ->where('provider_id', $provider->id)
                ->whereNull('settlement_id')
                ->where('type', '!=', LedgerType::Payout)
                ->where('available_at', '<=', $until->copy()->endOfDay())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($entries->isEmpty()) {
                throw new BusinessException(__('admin.settlements.nothing_to_settle'), 'nothing_to_settle');
            }

            $sum = fn (array $types) => round($entries->whereIn('type', $types)->sum('amount'), 2);
            $net = round($entries->sum('amount'), 2);

            if ($net <= 0 || ($enforceMinimum && $net < (float) Setting::get('settlement_min_amount'))) {
                throw new BusinessException(__('admin.settlements.below_minimum', ['amount' => number_format($net, 2)]), 'below_minimum');
            }

            $settlement = Settlement::create([
                'number' => DocNumber::make('STL', 'settlements'),
                'provider_id' => $provider->id,
                'period_from' => $entries->min('created_at')->toDateString(),
                'period_to' => $until->toDateString(),
                'gross_amount' => $sum([LedgerType::Sale]),
                'commission_amount' => -$sum([LedgerType::Commission, LedgerType::CommissionReversal]),
                'refunds_amount' => -$sum([LedgerType::Refund]),
                'adjustments_amount' => $sum([LedgerType::Adjustment]),
                'net_amount' => $net,
                'status' => SettlementStatus::PendingApproval,
                'bank_snapshot' => [
                    'bank_name' => $provider->bank_name,
                    'iban' => $provider->iban,
                    'account_holder' => $provider->account_holder,
                ],
                'created_by' => $by?->id,
            ]);

            // Never re-attach an entry that another settlement already took.
            $attached = LedgerEntry::whereIn('id', $entries->pluck('id'))->whereNull('settlement_id')->update(['settlement_id' => $settlement->id]);
            if ($attached !== $entries->count()) {
                throw new BusinessException(__('admin.settlements.wrong_status'), 'concurrent_settlement');
            }

            return $settlement;
        });
    }

    /** Run the cycle for every approved provider. Returns [created, skipped]. */
    public function generateAll(CarbonInterface $until, ?User $by = null): array
    {
        $created = 0;
        $skipped = 0;

        Provider::where('status', ProviderStatus::Approved)->each(function (Provider $provider) use ($until, $by, &$created, &$skipped) {
            try {
                $this->generate($provider, $until, $by);
                $created++;
            } catch (BusinessException) {
                $skipped++;
            }
        });

        return [$created, $skipped];
    }

    public function approve(Settlement $settlement, User $by): Settlement
    {
        DB::transaction(function () use ($settlement, $by) {
            $locked = $this->lock($settlement);
            $this->assertStatus($locked, SettlementStatus::PendingApproval);

            if (empty($locked->bank_snapshot['iban'])) {
                throw new BusinessException(__('admin.settlements.missing_iban'), 'missing_iban');
            }

            $locked->update(['status' => SettlementStatus::Approved, 'approved_by' => $by->id, 'approved_at' => now()]);
        });

        return $settlement->refresh();
    }

    public function reject(Settlement $settlement, User $by, string $notes): Settlement
    {
        DB::transaction(function () use ($settlement, $notes) {
            $locked = $this->lock($settlement);

            if (! in_array($locked->status, [SettlementStatus::PendingApproval, SettlementStatus::Approved], true)) {
                throw new BusinessException(__('admin.settlements.wrong_status'), 'wrong_status');
            }

            // Release the entries so they are picked up by the next cycle.
            LedgerEntry::where('settlement_id', $locked->id)->update(['settlement_id' => null]);
            $locked->update(['status' => SettlementStatus::Rejected, 'notes' => $notes]);
        });

        return $settlement->refresh();
    }

    public function markPaid(Settlement $settlement, User $by, string $reference, ?string $receiptPath = null): Settlement
    {
        DB::transaction(function () use ($settlement, $by, $reference, $receiptPath) {
            // Locked so a double click can never write two payout entries.
            $settlement = $this->lock($settlement);
            $this->assertStatus($settlement, SettlementStatus::Approved);

            $settlement->update([
                'status' => SettlementStatus::Paid,
                'paid_by' => $by->id,
                'paid_at' => now(),
                'payment_reference' => $reference,
                'receipt_path' => $receiptPath,
            ]);

            $this->ledger->entry('provider', LedgerType::Payout, -$settlement->net_amount, __('ledger.payout', ['number' => $settlement->number]), [
                'provider_id' => $settlement->provider_id,
                'settlement_id' => $settlement->id,
                'available_at' => now(),
            ]);
        });

        $settlement->refresh();

        SystemNotification::send($settlement->provider->owner, 'settlement_paid', [
            'number' => $settlement->number,
            'amount' => number_format($settlement->net_amount, 2),
        ], ['settlement_id' => $settlement->id]);

        return $settlement;
    }

    private function lock(Settlement $settlement): Settlement
    {
        return Settlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
    }

    private function assertStatus(Settlement $settlement, SettlementStatus $expected): void
    {
        if ($settlement->status !== $expected) {
            throw new BusinessException(__('admin.settlements.wrong_status'), 'wrong_status');
        }
    }
}
