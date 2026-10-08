<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\SystemNotification;
use App\Services\Integrations\Contracts\PaymentGateway;
use App\Services\Integrations\IntegrationLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private PaymentGateway $gateway,
        private OrderService $orders,
        private RefundService $refunds,
    ) {}

    /** @param  Collection<int, Order>  $orders */
    public function checkout(User $user, Collection $orders): Payment
    {
        foreach ($orders as $order) {
            if ($order->user_id !== $user->id || $order->status !== OrderStatus::PendingPayment) {
                throw new BusinessException(__('api.payments.order_not_payable'), 'order_not_payable');
            }
        }

        $payment = Payment::create([
            'reference' => (string) Str::uuid(),
            'user_id' => $user->id,
            'amount' => round($orders->sum('total'), 2),
            'currency' => config('farah.currency'),
            'gateway' => config('farah.integrations.payment.driver'),
            'status' => PaymentStatus::Initiated,
            // Orders this payment was created for; they stay attached even if a retry re-links them.
            'payload' => ['order_ids' => $orders->pluck('id')->values()->all()],
        ]);

        $checkout = IntegrationLogger::call('payment', 'create_checkout', ['reference' => $payment->reference, 'amount' => $payment->amount],
            fn () => $this->gateway->createCheckout($payment, ['orders' => $orders->pluck('number')->all()]));

        $payment->update(['gateway_payment_id' => $checkout['id'], 'checkout_url' => $checkout['checkout_url']]);
        Order::whereIn('id', $orders->pluck('id'))->update(['payment_id' => $payment->id]);

        return $payment;
    }

    /**
     * Process a gateway webhook exactly once (unique gateway+event_id).
     * Returns false if the event was a duplicate.
     *
     * The signature is verified before the event id is claimed, so a forged
     * request can never "use up" the id of a genuine event.
     */
    public function handleWebhook(string $rawBody, ?string $signature): bool
    {
        $payload = json_decode($rawBody, true);
        $payload = is_array($payload) ? $payload : [];
        $valid = $this->gateway->verifySignature($rawBody, $signature);
        $data = $this->gateway->parseWebhook($payload);
        $gateway = config('farah.integrations.payment.driver');

        if (! $valid) {
            WebhookEvent::create([
                'gateway' => $gateway,
                'event_id' => 'invalid:'.Str::uuid(), // never collides with (or blocks) the genuine event id
                'type' => $data['type'] ?? null,
                'payload' => $payload,
                'signature_valid' => false,
                'error' => 'invalid signature (claimed id: '.Str::limit((string) ($data['event_id'] ?? '-'), 100).')',
            ]);

            throw new BusinessException('Invalid signature', 'invalid_signature', 401);
        }

        if (! $data['event_id']) {
            throw new BusinessException('Missing event id', 'invalid_webhook', 400);
        }

        try {
            $event = WebhookEvent::create([
                'gateway' => $gateway,
                'event_id' => $data['event_id'],
                'type' => $data['type'],
                'payload' => $payload,
                'signature_valid' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false; // already received
        }

        try {
            $payment = Payment::where('reference', $data['payment_reference'])->firstOrFail();

            if ($data['status'] === 'paid') {
                $this->assertAmountMatches($payment, $data);
                $this->markPaid($payment, $data['gateway_payment_id'], $data['method']);
            } else {
                $this->markFailed($payment, $data['failure_reason']);
            }

            $event->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            $event->update(['error' => $e->getMessage()]);
            throw $e;
        }

        return true;
    }

    public function markPaid(Payment $payment, ?string $gatewayId = null, ?string $method = null): void
    {
        $claimed = DB::transaction(function () use ($payment, $gatewayId, $method) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)) {
                return false; // already processed
            }

            $locked->update([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'gateway_payment_id' => $gatewayId ?? $locked->gateway_payment_id,
                'method' => $method,
                'failure_reason' => null,
            ]);

            return true;
        });

        if (! $claimed) {
            return;
        }

        $payment->refresh();

        // Orders created for this payment, plus any currently linked to it.
        $ids = collect($payment->payload['order_ids'] ?? [])
            ->merge(Order::where('payment_id', $payment->id)->pluck('id'))
            ->map(fn ($id) => (int) $id)->unique()->sort()->values();

        foreach (Order::whereIn('id', $ids)->get() as $order) {
            try {
                $this->orders->confirm($order, $payment);
            } catch (BusinessException $e) {
                if ($e->errorCode === 'already_paid') {
                    // Paid twice (e.g. two checkout sessions): return this payment's share.
                    $this->refunds->refundDuplicatePayment($payment, $order);
                } else {
                    // The slots were booked by someone else after the hold expired, or the venue is gone.
                    $order->update(['payment_id' => $payment->id]);
                    $this->refunds->refundUnfulfillable($order->fresh());
                }
            }
        }
    }

    public function markFailed(Payment $payment, ?string $reason = null): void
    {
        $updated = Payment::whereKey($payment->id)->where('status', PaymentStatus::Initiated)
            ->update(['status' => PaymentStatus::Failed, 'failure_reason' => $reason]);

        if ($updated === 0) {
            return; // already paid / failed
        }

        $payment->refresh();

        if ($payment->user) {
            SystemNotification::send($payment->user, 'payment_failed', [], ['payment_reference' => $payment->reference]);
        }
    }

    /** A captured amount / currency that differs from what we asked for is never auto-confirmed. */
    private function assertAmountMatches(Payment $payment, array $data): void
    {
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;

        $amountMismatch = $amount !== null && abs((float) $amount - $payment->amount) > 0.001;
        $currencyMismatch = $currency !== null && strtoupper((string) $currency) !== strtoupper($payment->currency);

        if ($amountMismatch || $currencyMismatch) {
            $payment->update(['failure_reason' => 'amount_mismatch: '.$amount.' '.$currency]);

            throw new BusinessException(__('api.payments.amount_mismatch'), 'amount_mismatch', 422);
        }
    }
}
