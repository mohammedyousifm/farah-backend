<?php

namespace Tests\Feature\Services;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\BusinessException;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use App\Services\Integrations\Contracts\PaymentGateway;
use App\Services\Integrations\Drivers\FakePaymentGateway;
use App\Services\OrderService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Tests\Feature\Api\ApiTestCase;

/**
 * Service-level tests for the money flows. Reuses the API fixtures
 * (reference data, approved provider, published venue with 100 SAR / hour tables, 10% global commission).
 *
 * Run: php artisan test tests/Feature/Services   (database farah_test)
 */
abstract class ServiceTestCase extends ApiTestCase
{
    protected function admin(): User
    {
        return User::create([
            'name' => 'Finance',
            'email' => 'finance'.random_int(1000, 9999).'@farah.test',
            'type' => UserType::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    /** Pending online order (one table, $slots hours) + an initiated payment for it. */
    protected function checkout(User $customer, Venue $venue, int $slots = 2, ?CarbonImmutable $startsAt = null): array
    {
        $order = app(OrderService::class)->createOnline($customer, [$this->line($venue, $slots, $startsAt)]);
        $payment = app(PaymentService::class)->checkout($customer, collect([$order]));

        return [$order->fresh(), $payment];
    }

    protected function paidOrder(Venue $venue, int $slots = 2, ?User $customer = null, ?CarbonImmutable $startsAt = null): Order
    {
        [$order, $payment] = $this->checkout($customer ?? $this->customer('+9665'.random_int(10000000, 99999999)), $venue, $slots, $startsAt);
        app(PaymentService::class)->markPaid($payment, 'pay_test', 'mada');

        return $order->fresh(['bookings', 'venue']);
    }

    /**
     * Paid online order with several bookings (one per line).
     *
     * @param  array<int, array{activity_id: int, resource_id?: ?int, starts_at: string, slots: int}>  $lines
     */
    protected function paidLines(array $lines, ?User $customer = null): Order
    {
        $customer ??= $this->customer('+9665'.random_int(10000000, 99999999));
        $order = app(OrderService::class)->createOnline($customer, $lines);
        app(PaymentService::class)->markPaid(app(PaymentService::class)->checkout($customer, collect([$order])), 'pay_test', 'mada');

        return $order->fresh(['bookings', 'venue']);
    }

    protected function signedWebhook(Payment $payment, string $eventId, array $data = [], string $type = 'payment.paid'): bool
    {
        $body = json_encode(['id' => $eventId, 'type' => $type, 'data' => $data + ['reference' => $payment->reference, 'id' => 'pay_1', 'method' => 'mada']]);

        return app(PaymentService::class)->handleWebhook($body, FakePaymentGateway::sign($body));
    }

    protected function ledgerSum(Order $order, string $account, ?string $type = null): float
    {
        return round((float) LedgerEntry::where('order_id', $order->id)->where('account', $account)
            ->when($type, fn ($q) => $q->where('type', $type))->sum('amount'), 2);
    }

    /** Swap the payment gateway for one whose refunds fail. */
    protected function failingRefunds(): void
    {
        $this->app->instance(PaymentGateway::class, new class extends FakePaymentGateway
        {
            public function refund(Payment $payment, float $amount, string $reason = ''): array
            {
                return ['id' => null, 'status' => 'failed'];
            }
        });
    }

    protected function assertBusiness(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected BusinessException [{$code}]");
        } catch (BusinessException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
