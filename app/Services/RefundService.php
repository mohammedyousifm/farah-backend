<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Integrations\Contracts\PaymentGateway;
use App\Services\Integrations\IntegrationLogger;
use App\Support\DocNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Full and partial refunds (BR-007). Commission is reversed in proportion
 * to the refunded share of the order; the slots go back on sale.
 *
 * A refund covers the whole order or some of its bookings: items = [{booking_id}].
 *
 * Concurrency: every state change locks the order row first, and money that
 * is approved but not yet processed counts as reserved, so two admins (or an
 * admin and a provider cancellation) can never refund more than was paid.
 */
class RefundService
{
    public function __construct(
        private PaymentGateway $gateway,
        private LedgerService $ledger,
    ) {}

    /** Amount the customer is entitled to under the venue's refund policy right now. */
    public function policyQuote(Order $order, ?array $items = null): array
    {
        $venue = $order->venue;
        $bookings = $this->selectedBookings($order, $items);
        // The policy clock runs to the earliest booking being given up.
        $start = $bookings->min('starts_at') ?? now();
        $hoursLeft = now()->diffInHours($start, false);
        $percent = 0;

        foreach (config("farah.refund_policies.{$venue->refund_policy}", [0 => 0]) as $minHours => $pct) {
            if ($hoursLeft >= $minHours) {
                $percent = $pct;
                break;
            }
        }

        $base = $items ? round($bookings->sum('total'), 2) : $order->refundableAmount();

        return [
            'policy' => $venue->refund_policy,
            'hours_before_start' => max(0, (int) $hoursLeft),
            'percent' => $percent,
            'amount' => round(min($base * $percent / 100, $order->refundableAmount()), 2),
        ];
    }

    /** Customer asks for a refund; admin reviews it. */
    public function request(Order $order, User $by, ?array $items, ?string $reason): Refund
    {
        return DB::transaction(function () use ($order, $by, $items, $reason) {
            $order = $this->lockOrder($order);
            $this->assertRefundable($order);

            if ($order->refunds()->whereIn('status', [RefundStatus::Requested, RefundStatus::Approved])->exists()) {
                throw new BusinessException(__('api.refunds.already_requested'), 'refund_pending');
            }

            // Every booking already given up (e.g. an earlier partial-percentage refund): nothing left to claim.
            if ($this->activeBookings($order)->isEmpty()) {
                throw new BusinessException(__('api.refunds.nothing_left'), 'nothing_left');
            }

            $quote = $this->policyQuote($order, $items);

            if ($quote['amount'] <= 0) {
                throw new BusinessException(__('api.refunds.not_eligible'), 'refund_not_eligible');
            }

            return Refund::create([
                'number' => DocNumber::make('RF', 'refunds'),
                'order_id' => $order->id,
                'payment_id' => $order->payment_id,
                'user_id' => $order->user_id,
                'type' => $this->isFull($order, $items) ? 'full' : 'partial',
                'amount' => $quote['amount'],
                'items' => $items,
                'reason' => $reason,
                'status' => RefundStatus::Requested,
                'requested_by' => $by->id,
            ]);
        });
    }

    /** Admin-initiated refund of any amount (e.g. venue closed, dispute resolved). */
    public function createByAdmin(Order $order, float $amount, ?array $items, ?string $reason, User $by): Refund
    {
        $refund = DB::transaction(function () use ($order, $amount, $items, $reason, $by) {
            $order = $this->lockOrder($order);
            $this->assertRefundable($order);

            $amount = round($amount, 2);
            $available = $this->availableToRefund($order);

            if ($amount <= 0 || $amount > $available + 0.001) {
                throw new BusinessException(__('api.refunds.invalid_amount', ['max' => number_format($available, 2)]), 'invalid_amount');
            }

            // Created already approved (reserving the amount) so no half-created request is left behind.
            return Refund::create([
                'number' => DocNumber::make('RF', 'refunds'),
                'order_id' => $order->id,
                'payment_id' => $order->payment_id,
                'user_id' => $order->user_id,
                'type' => abs($amount - $order->refundableAmount()) < 0.01 ? 'full' : 'partial',
                'amount' => $amount,
                'items' => $items,
                'reason' => $reason,
                'status' => RefundStatus::Approved,
                'requested_by' => $by->id,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
            ]);
        });

        return $this->process($refund);
    }

    public function approve(Refund $refund, User $by, ?float $amount = null, ?string $notes = null): Refund
    {
        $approved = DB::transaction(function () use ($refund, $by, $amount, $notes) {
            $order = $this->lockOrder($refund->order);
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RefundStatus::Requested) {
                throw new BusinessException(__('api.refunds.not_pending'), 'refund_not_pending');
            }

            $amount = round($amount ?? $locked->amount, 2);
            $available = $this->availableToRefund($order, $locked->id);

            if ($amount <= 0 || $amount > $available + 0.001) {
                throw new BusinessException(__('api.refunds.invalid_amount', ['max' => number_format($available, 2)]), 'invalid_amount');
            }

            $locked->update([
                'amount' => $amount,
                'status' => RefundStatus::Approved,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'admin_notes' => $notes,
            ]);

            return $locked;
        });

        return $this->process($approved);
    }

    public function reject(Refund $refund, User $by, string $notes): Refund
    {
        $updated = Refund::whereKey($refund->id)->where('status', RefundStatus::Requested)
            ->update(['status' => RefundStatus::Rejected, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'admin_notes' => $notes]);

        if ($updated === 0) {
            throw new BusinessException(__('api.refunds.not_pending'), 'refund_not_pending');
        }

        $refund->refresh();

        if ($refund->user) {
            SystemNotification::send($refund->user, 'refund_rejected', ['number' => $refund->order->number], ['order_id' => $refund->order_id]);
        }

        return $refund;
    }

    /** Payment captured but the order could not be fulfilled (slot taken / venue gone): refund everything. Idempotent. */
    public function refundUnfulfillable(Order $order): void
    {
        $refund = DB::transaction(function () use ($order) {
            $order = $this->lockOrder($order);

            if ($order->isPaid() || $order->refunds()->where('reason', 'sold_out_after_payment')->whereNot('status', RefundStatus::Failed)->exists()) {
                return null;
            }

            return Refund::create([
                'number' => DocNumber::make('RF', 'refunds'),
                'order_id' => $order->id,
                'payment_id' => $order->payment_id,
                'user_id' => $order->user_id,
                'type' => 'full',
                'amount' => $order->total,
                'reason' => 'sold_out_after_payment',
                'status' => RefundStatus::Approved,
                'reviewed_at' => now(),
            ]);
        });

        if (! $refund) {
            return;
        }

        $payment = $order->payment()->first();
        $this->callGateway($refund, $payment, 'sold out');

        DB::transaction(function () use ($refund, $order, $payment) {
            $refund->update(['status' => RefundStatus::Processed, 'processed_at' => now(), 'gateway_refund_id' => $refund->gateway_refund_id]);
            $this->addToPayment($payment, $refund->amount);
            Order::whereKey($order->id)->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => 'sold_out_after_payment',
                'refunded_amount' => $refund->amount,
            ]);
        });

        if ($refund->user) {
            SystemNotification::send($refund->user, 'refund_processed', ['number' => $order->number, 'amount' => number_format($refund->amount, 2)], ['order_id' => $order->id]);
        }
    }

    /**
     * A second payment was captured for an order already paid by another one
     * (e.g. two checkout sessions): give the extra money back. The order itself
     * is untouched, so no ledger entries are written.
     */
    public function refundDuplicatePayment(Payment $payment, Order $order): ?Refund
    {
        $refund = DB::transaction(function () use ($payment, $order) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $amount = round(min($order->total, $locked->amount - $locked->refunded_amount), 2);

            if ($amount <= 0 || Refund::where('payment_id', $locked->id)->where('order_id', $order->id)
                ->where('reason', 'duplicate_payment')->whereNot('status', RefundStatus::Failed)->exists()) {
                return null;
            }

            return Refund::create([
                'number' => DocNumber::make('RF', 'refunds'),
                'order_id' => $order->id,
                'payment_id' => $locked->id,
                'user_id' => $locked->user_id,
                'type' => 'full',
                'amount' => $amount,
                'reason' => 'duplicate_payment',
                'status' => RefundStatus::Approved,
                'reviewed_at' => now(),
            ]);
        });

        if (! $refund) {
            return null;
        }

        $this->callGateway($refund, $payment, 'duplicate payment');

        DB::transaction(function () use ($refund, $payment) {
            $refund->update(['status' => RefundStatus::Processed, 'processed_at' => now(), 'gateway_refund_id' => $refund->gateway_refund_id]);
            $this->addToPayment($payment, $refund->amount);
        });

        return $refund->fresh();
    }

    private function process(Refund $refund): Refund
    {
        $order = $refund->order;

        // 1. Money back through the gateway (online orders only; offline sales were paid to the provider).
        if ($order->channel === 'online' && $order->payment) {
            $this->callGateway($refund, $order->payment, (string) $refund->reason);
        }

        // 2. Books: order, payment, bookings, slots and ledger, all under the order lock.
        DB::transaction(function () use ($refund) {
            $order = $this->lockOrder($refund->order);
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RefundStatus::Approved) {
                return; // processed concurrently
            }

            $refunded = round($order->refunded_amount + $locked->amount, 2);
            $isFull = $refunded >= $order->total - 0.01;

            // Proportional commission reversal; the last refund reverses whatever is left, so the
            // sum of reversals always equals the commission charged (no rounding drift).
            $alreadyReversed = (float) $order->refunds()->where('status', RefundStatus::Processed)->sum('commission_reversed');
            $remainingCommission = round($order->commission_amount - $alreadyReversed, 2);
            $share = $order->total > 0 ? $locked->amount / $order->total : 0;
            $commissionBack = $isFull
                ? $remainingCommission
                : min(round($order->commission_amount * $share, 2), $remainingCommission);
            $commissionBack = max(0.0, $commissionBack);

            $locked->update([
                'status' => RefundStatus::Processed,
                'processed_at' => now(),
                'commission_reversed' => $commissionBack,
                'provider_debit' => round($locked->amount - $commissionBack, 2),
                'gateway_refund_id' => $refund->gateway_refund_id,
            ]);

            $order->update([
                'refunded_amount' => $refunded,
                'status' => $isFull ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded,
            ]);

            if ($order->channel === 'online') {
                $this->addToPayment($order->payment()->first(), $locked->amount);
            }

            // Bookings follow the refund's scope, not the money: a "full" request refunded at a
            // policy percentage (e.g. 50%) still gives up every remaining booking.
            $this->cancelBookings($order, $locked->items, $isFull || ($locked->type === 'full' && ! $locked->items));
            $this->ledger->recordRefund($locked);
        });

        $refund->refresh();

        if ($refund->user && $refund->status === RefundStatus::Processed) {
            SystemNotification::send($refund->user, 'refund_processed', ['number' => $order->number, 'amount' => number_format($refund->amount, 2)], ['order_id' => $order->id]);
        }

        return $refund;
    }

    /** Refund through the gateway; any failure marks the refund failed (releasing the reserved amount). */
    private function callGateway(Refund $refund, ?Payment $payment, string $reason): void
    {
        if (! $payment) {
            return;
        }

        try {
            $result = IntegrationLogger::call('payment', 'refund', ['refund' => $refund->number, 'amount' => $refund->amount],
                fn () => $this->gateway->refund($payment, $refund->amount, $reason));
        } catch (\Throwable) {
            $result = null;
        }

        if (($result['status'] ?? null) !== 'succeeded') {
            $refund->update(['status' => RefundStatus::Failed]);
            throw new BusinessException(__('api.refunds.gateway_failed'), 'gateway_failed');
        }

        $refund->gateway_refund_id = $result['id'] ?? null;
    }

    private function addToPayment(?Payment $payment, float $amount): void
    {
        if (! $payment) {
            return;
        }

        $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
        $refunded = round($payment->refunded_amount + $amount, 2);

        $payment->update([
            'refunded_amount' => $refunded,
            'status' => $refunded >= $payment->amount - 0.01 ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
        ]);
    }

    private function cancelBookings(Order $order, ?array $items, bool $isFull): void
    {
        // An amount-only partial refund (goodwill, dispute settlement) gives up no booking.
        if (! $isFull && ! $items) {
            return;
        }

        $bookings = $isFull ? $this->activeBookings($order) : $this->selectedBookings($order, $items);

        app(OrderService::class)->releaseBookings($bookings);
    }

    /** Bookings still held by the order (not cancelled or expired). */
    private function activeBookings(Order $order): Collection
    {
        return $order->bookings->filter(fn ($b) => $b->isActive())->values();
    }

    /** The active bookings a partial refund is about; all of them when no items are given. */
    private function selectedBookings(Order $order, ?array $items): Collection
    {
        $active = $this->activeBookings($order);

        if (! $items) {
            return $active;
        }

        $ids = array_unique(array_map(fn ($line) => (int) ($line['booking_id'] ?? 0), $items));
        $selected = $active->whereIn('id', $ids)->values();

        if ($selected->count() !== count($ids)) {
            throw new BusinessException(__('api.refunds.invalid_items'), 'invalid_items');
        }

        return $selected;
    }

    private function lockOrder(Order $order): Order
    {
        return Order::whereKey($order->id)->lockForUpdate()->firstOrFail()->load('venue', 'bookings');
    }

    /** Refundable money minus refunds already approved but not processed yet. */
    private function availableToRefund(Order $order, ?int $exceptRefundId = null): float
    {
        $reserved = (float) $order->refunds()
            ->where('status', RefundStatus::Approved)
            ->when($exceptRefundId, fn ($q) => $q->whereKeyNot($exceptRefundId))
            ->sum('amount');

        return max(0.0, round($order->refundableAmount() - $reserved, 2));
    }

    private function assertRefundable(Order $order): void
    {
        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::PartiallyRefunded, OrderStatus::Completed], true)) {
            throw new BusinessException(__('api.refunds.order_not_refundable'), 'order_not_refundable');
        }
        if ($order->refundableAmount() <= 0) {
            throw new BusinessException(__('api.refunds.nothing_left'), 'nothing_left');
        }
    }

    private function isFull(Order $order, ?array $items): bool
    {
        return ! $items || $this->selectedBookings($order, $items)->count() >= $this->activeBookings($order)->count();
    }
}
