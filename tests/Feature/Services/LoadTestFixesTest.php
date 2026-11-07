<?php

namespace Tests\Feature\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\WebhookEvent;
use App\Services\InvoiceService;
use App\Services\OrderService;

/** Problems found by the local load test of 6 Nov 2026 (50,000 customers). */
class LoadTestFixesTest extends ServiceTestCase
{
    public function test_a_webhook_that_failed_half_way_is_applied_when_the_gateway_retries_it(): void
    {
        [$order, $payment] = $this->checkout($this->customer(), $this->venue($this->provider()), 1);

        // First attempt: the payment was marked paid, then confirming the order failed (a database conflict → 500).
        $payment->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        WebhookEvent::create(['gateway' => 'fake', 'event_id' => 'evt_retry', 'type' => 'payment.paid', 'payload' => [], 'signature_valid' => true, 'error' => 'Deadlock']);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);

        // The gateway sends the same event again: it must finish the job, not be ignored as a duplicate.
        $this->assertTrue($this->signedWebhook($payment, 'evt_retry'));
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertNotNull(WebhookEvent::where('event_id', 'evt_retry')->value('processed_at'));

        // A third copy after success is only acknowledged.
        $this->assertFalse($this->signedWebhook($payment, 'evt_retry'));
    }

    public function test_a_retry_after_the_hold_expired_books_again_or_refunds_never_keeps_the_money(): void
    {
        [$order, $payment] = $this->checkout($this->customer(), $this->venue($this->provider()), 1);
        $payment->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        WebhookEvent::create(['gateway' => 'fake', 'event_id' => 'evt_late', 'type' => 'payment.paid', 'payload' => [], 'signature_valid' => true, 'error' => 'Deadlock']);
        app(OrderService::class)->expire($order->fresh()); // the hold ran out meanwhile

        $this->assertTrue($this->signedWebhook($payment, 'evt_late'));

        // The table was still free: the order is confirmed again. (If it was gone, it would be refunded.)
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    public function test_invoice_numbers_stay_gapless_and_unique(): void
    {
        $venue = $this->venue($this->provider());
        $numbers = collect(['14:00', '16:00', '18:00'])->map(function (string $time) use ($venue) {
            [$order, $payment] = $this->checkout($this->customer('+9665'.random_int(10000000, 99999999)), $venue, 1, $this->slot(3, $time));
            $this->signedWebhook($payment, 'evt_'.$order->id);

            return app(InvoiceService::class)->issue($order->fresh())->invoice_number;
        });

        $this->assertCount(3, $numbers->unique());
        $tails = $numbers->map(fn ($n) => (int) substr($n, -6))->sort()->values();
        $this->assertSame([$tails[0], $tails[0] + 1, $tails[0] + 2], $tails->all());
    }
}
