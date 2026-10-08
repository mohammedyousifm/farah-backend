<?php

namespace App\Services\Integrations\Contracts;

use App\Models\Payment;

/**
 * Third-party services sit behind interfaces so real providers (Moyasar/HyperPay,
 * Unifonic/Taqnyat, Wathq, Qoyod/Daftra, FCM) can be swapped via config/farah.php.
 */
interface PaymentGateway
{
    /** Create a hosted checkout. Returns ['id' => gateway payment id, 'checkout_url' => string]. */
    public function createCheckout(Payment $payment, array $meta = []): array;

    /** Refund part or all of a captured payment. Returns ['id' => refund id, 'status' => 'succeeded'|'failed']. */
    public function refund(Payment $payment, float $amount, string $reason = ''): array;

    /** Validate the webhook signature for a raw request body. */
    public function verifySignature(string $payload, ?string $signature): bool;

    /**
     * Normalise a webhook payload to
     * ['event_id', 'type', 'payment_reference', 'gateway_payment_id', 'status' => paid|failed, 'method', 'failure_reason',
     *  'amount' => captured amount or null, 'currency' => ISO code or null].
     * amount / currency are compared with the payment before orders are confirmed.
     */
    public function parseWebhook(array $payload): array;
}
