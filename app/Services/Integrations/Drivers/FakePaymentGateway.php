<?php

namespace App\Services\Integrations\Drivers;

use App\Models\Payment;
use App\Services\Integrations\Contracts\PaymentGateway;
use Illuminate\Support\Str;

/**
 * Local sandbox gateway. The checkout URL opens a simple page (routes/web.php →
 * payments.fake) with "pay" / "fail" buttons that fire a signed webhook, so the
 * mobile team can exercise the full payment flow without real credentials.
 */
class FakePaymentGateway implements PaymentGateway
{
    public function createCheckout(Payment $payment, array $meta = []): array
    {
        return [
            'id' => 'fake_'.Str::lower(Str::random(16)),
            'checkout_url' => url('payments/fake/'.$payment->reference), // web route "payments.fake"
        ];
    }

    public function refund(Payment $payment, float $amount, string $reason = ''): array
    {
        return ['id' => 'fake_rf_'.Str::lower(Str::random(12)), 'status' => 'succeeded'];
    }

    public function verifySignature(string $payload, ?string $signature): bool
    {
        // An empty secret, or the "change-me" placeholder on a real server, would let anyone compute a valid HMAC.
        $secret = (string) config('farah.integrations.payment.webhook_secret');
        if ($secret === '' || ($secret === 'change-me' && ! app()->environment('local', 'testing'))) {
            return false;
        }

        return $signature !== null && hash_equals(self::sign($payload), $signature);
    }

    public function parseWebhook(array $payload): array
    {
        return [
            'event_id' => $payload['id'] ?? null,
            'type' => $payload['type'] ?? null,
            'payment_reference' => $payload['data']['reference'] ?? null,
            'gateway_payment_id' => $payload['data']['id'] ?? null,
            'status' => ($payload['type'] ?? '') === 'payment.paid' ? 'paid' : 'failed',
            'method' => $payload['data']['method'] ?? 'mada',
            'failure_reason' => $payload['data']['message'] ?? null,
            'amount' => isset($payload['data']['amount']) ? (float) $payload['data']['amount'] : null,
            'currency' => $payload['data']['currency'] ?? null,
        ];
    }

    public static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('farah.integrations.payment.webhook_secret'));
    }
}
