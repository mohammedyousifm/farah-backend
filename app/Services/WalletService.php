<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserType;
use App\Enums\WalletTransactionType;
use App\Exceptions\BusinessException;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Farah wallet. Money comes in three ways only: a refund, compensation or a reward.
 * There is no top-up. The customer pays an order from it when the balance covers the
 * whole amount; an order is never split between the wallet and a card.
 *
 * Every movement locks the user row, so two requests can never spend the same balance.
 */
class WalletService
{
    public const GATEWAY = 'wallet';

    /** Add money to a wallet. Call inside the transaction of whatever caused it (e.g. a refund). */
    public function credit(User $user, float $amount, WalletTransactionType $type, array $attributes = []): WalletTransaction
    {
        return $this->move($user, abs(round($amount, 2)), $type, $attributes);
    }

    /** Compensation or a reward added by an admin. */
    public function grant(User $user, float $amount, WalletTransactionType $type, string $note, User $by, ?Complaint $complaint = null): WalletTransaction
    {
        $amount = round($amount, 2);
        $max = (float) config('farah.wallet.max_grant');

        if (! in_array($type, WalletTransactionType::grantable(), true)) {
            throw new BusinessException(__('api.wallet.invalid_type'), 'invalid_type');
        }
        if ($user->type === UserType::Admin) {
            throw new BusinessException(__('api.wallet.customers_only'), 'customers_only');
        }
        if ($amount <= 0 || $amount > $max) {
            throw new BusinessException(__('api.wallet.invalid_amount', ['max' => number_format($max, 2)]), 'invalid_amount');
        }

        $transaction = $this->move($user, $amount, $type, [
            'note' => $note,
            'created_by' => $by->id,
            'complaint_id' => $complaint?->id,
            'order_id' => $complaint?->order_id,
        ]);

        SystemNotification::send($user, 'wallet_'.$type->value, ['amount' => number_format($amount, 2)], ['wallet' => true]);

        return $transaction;
    }

    /** Pay a pending order from the wallet. The balance must cover the whole order. */
    public function pay(User $user, Order $order, PaymentService $payments): Payment
    {
        $payment = DB::transaction(function () use ($user, $order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->user_id !== $user->id || $order->status !== OrderStatus::PendingPayment) {
                throw new BusinessException(__('api.payments.order_not_payable'), 'order_not_payable');
            }

            if ($this->balance($user) < $order->total - 0.001) {
                throw new BusinessException(__('api.wallet.not_enough'), 'wallet_not_enough');
            }

            $payment = Payment::create([
                'reference' => (string) Str::uuid(),
                'user_id' => $user->id,
                'amount' => $order->total,
                'currency' => config('farah.currency'),
                'gateway' => self::GATEWAY,
                'status' => PaymentStatus::Initiated,
                'payload' => ['order_ids' => [$order->id]],
            ]);

            $this->move($user, -$order->total, WalletTransactionType::Payment, ['order_id' => $order->id, 'payment_id' => $payment->id]);
            $order->update(['payment_id' => $payment->id]);

            return $payment;
        });

        // Confirms the order; if the slots are gone the money goes straight back to the wallet.
        $payments->markPaid($payment, null, self::GATEWAY);

        return $payment->fresh('orders');
    }

    public function balance(User $user): float
    {
        return round((float) User::whereKey($user->id)->value('wallet_balance'), 2);
    }

    private function move(User $user, float $amount, WalletTransactionType $type, array $attributes): WalletTransaction
    {
        return DB::transaction(function () use ($user, $amount, $type, $attributes) {
            $balance = round((float) User::withTrashed()->whereKey($user->id)->lockForUpdate()->value('wallet_balance') + $amount, 2);

            if ($balance < 0) {
                throw new BusinessException(__('api.wallet.not_enough'), 'wallet_not_enough');
            }

            User::withTrashed()->whereKey($user->id)->update(['wallet_balance' => $balance]);
            $user->wallet_balance = $balance;
            $user->syncOriginalAttribute('wallet_balance');

            return WalletTransaction::create($attributes + [
                'user_id' => $user->id,
                'type' => $type,
                'amount' => round($amount, 2),
                'balance_after' => $balance,
            ]);
        });
    }
}
