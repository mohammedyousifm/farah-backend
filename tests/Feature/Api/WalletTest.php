<?php

namespace Tests\Feature\Api;

use App\Enums\UserType;
use App\Enums\WalletTransactionType;
use App\Exceptions\BusinessException;
use App\Models\BookingSlot;
use App\Models\Complaint;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\WalletService;
use App\Support\DocNumber;
use Laravel\Sanctum\Sanctum;

/**
 * The Farah wallet: money comes in from refunds, compensation and rewards only (no top-up),
 * and pays for an order when it covers the whole amount.
 */
class WalletTest extends ApiTestCase
{
    private function admin(): User
    {
        return User::firstOrCreate(['email' => 'finance@farah.sa'], ['name' => 'Finance', 'password' => 'secret-pass-123', 'type' => UserType::Admin]);
    }

    /** A pending order of 100 SAR (one hour) for the customer. */
    private function book(User $customer, int $slots = 1, int $days = 3): array
    {
        $venue = $this->venue($this->provider());
        Sanctum::actingAs($customer);

        return $this->postJson('/api/v1/bookings', $this->line($venue, $slots, $this->slot($days)))->assertCreated()->json('data');
    }

    public function test_an_empty_wallet_and_no_way_to_top_it_up(): void
    {
        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/wallet')->assertOk()
            ->assertJsonPath('data.balance', 0)
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonCount(0, 'data.transactions');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.wallet_balance', 0);

        // No endpoint adds money, and the profile cannot set the balance.
        $this->postJson('/api/v1/wallet', ['amount' => 100])->assertStatus(405);
        $this->postJson('/api/v1/wallet/top-up', ['amount' => 100])->assertNotFound();
        $this->putJson('/api/v1/me', ['name' => 'Saad Alharbi', 'wallet_balance' => 500])->assertOk()->assertJsonPath('data.wallet_balance', 0);
    }

    public function test_compensation_and_reward_are_added_by_an_admin(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $wallet = app(WalletService::class);
        $complaint = Complaint::create([
            'number' => DocNumber::make('CMP', 'complaints'), 'user_id' => $customer->id,
            'type' => 'complaint', 'subject' => 'Late', 'body' => 'The table was not ready.',
        ]);

        $wallet->grant($customer, 50, WalletTransactionType::Compensation, 'Sorry for the wait', $admin, $complaint);
        $wallet->grant($customer, 25.5, WalletTransactionType::Reward, 'Tenth booking', $admin);

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/wallet')->assertOk()
            ->assertJsonPath('data.balance', 75.5)
            ->assertJsonCount(2, 'data.transactions')
            ->assertJsonPath('data.transactions.0.type.value', 'reward')
            ->assertJsonPath('data.transactions.0.amount', 25.5)
            ->assertJsonPath('data.transactions.0.note', 'Tenth booking')
            ->assertJsonPath('data.transactions.1.type.value', 'compensation')
            ->assertJsonPath('data.transactions.1.balance_after', 50);

        $this->assertSame($complaint->id, WalletTransaction::where('type', 'compensation')->value('complaint_id'));
        $this->assertSame(1, $customer->notifications()->where('data->key', 'wallet_compensation')->count());
        $this->assertSame(1, $customer->notifications()->where('data->key', 'wallet_reward')->count());

        // Only compensation or a reward, within the limit, and never to an admin account.
        foreach ([
            fn () => $wallet->grant($customer, 10, WalletTransactionType::Refund, 'x', $admin),
            fn () => $wallet->grant($customer, 10, WalletTransactionType::Payment, 'x', $admin),
            fn () => $wallet->grant($customer, 0, WalletTransactionType::Reward, 'x', $admin),
            fn () => $wallet->grant($customer, config('farah.wallet.max_grant') + 1, WalletTransactionType::Reward, 'x', $admin),
            fn () => $wallet->grant($admin, 10, WalletTransactionType::Reward, 'x', $admin),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The grant should have been refused.');
            } catch (BusinessException) {
            }
        }
        $this->assertSame(75.5, $wallet->balance($customer));
    }

    public function test_the_wallet_pays_an_order_it_covers_in_full(): void
    {
        $customer = $this->customer();
        app(WalletService::class)->grant($customer, 150, WalletTransactionType::Compensation, 'Goodwill', $this->admin());
        $booked = $this->book($customer);
        $orderId = $booked['order']['id'];

        $this->postJson("/api/v1/orders/{$orderId}/pay-wallet")->assertOk()
            ->assertJsonPath('data.payment.status.value', 'paid')
            ->assertJsonPath('data.payment.method', 'wallet')
            ->assertJsonPath('data.payment.amount', 100)
            ->assertJsonPath('data.payment.orders.0.status.value', 'confirmed')
            ->assertJsonPath('data.wallet_balance', 50);

        $order = Order::findOrFail($orderId);
        $this->assertSame('confirmed', $order->status->value);
        $this->assertSame('wallet', $order->payment->gateway);
        $this->assertNotNull($order->invoice_number);
        // The provider is paid as for any other order.
        $this->assertEquals(100.0, (float) LedgerEntry::where('order_id', $orderId)->where('account', 'provider')->where('type', 'sale')->sum('amount'));

        $this->getJson('/api/v1/wallet')->assertOk()
            ->assertJsonPath('data.balance', 50)
            ->assertJsonPath('data.transactions.0.type.value', 'payment')
            ->assertJsonPath('data.transactions.0.amount', -100)
            ->assertJsonPath('data.transactions.0.order_number', $order->number);

        // Paying again changes nothing: the order is no longer pending.
        $this->postJson("/api/v1/orders/{$orderId}/pay-wallet")->assertStatus(422)->assertJsonPath('code', 'order_not_payable');
        $this->assertSame(50.0, app(WalletService::class)->balance($customer));
    }

    public function test_the_wallet_does_not_pay_part_of_an_order(): void
    {
        $customer = $this->customer();
        app(WalletService::class)->grant($customer, 99.99, WalletTransactionType::Reward, 'Almost', $this->admin());
        $booked = $this->book($customer);
        $orderId = $booked['order']['id'];

        $this->postJson("/api/v1/orders/{$orderId}/pay-wallet")->assertStatus(422)->assertJsonPath('code', 'wallet_not_enough');

        $this->assertSame('pending_payment', Order::findOrFail($orderId)->status->value);
        $this->assertSame(99.99, app(WalletService::class)->balance($customer));
        $this->assertSame(0, Payment::where('gateway', 'wallet')->count());
        $this->assertSame(0, WalletTransaction::where('type', 'payment')->count());
    }

    public function test_nobody_pays_from_another_customers_wallet(): void
    {
        $owner = $this->customer();
        $booked = $this->book($owner);
        $other = $this->customer('+966502222222');
        app(WalletService::class)->grant($other, 500, WalletTransactionType::Reward, 'Rich', $this->admin());

        Sanctum::actingAs($other);
        $this->postJson("/api/v1/orders/{$booked['order']['id']}/pay-wallet")->assertNotFound();
        $this->assertSame(500.0, app(WalletService::class)->balance($other));
    }

    public function test_a_refund_goes_to_the_wallet_and_pays_the_next_booking(): void
    {
        $customer = $this->customer();
        $booked = $this->book($customer, 1, 5);
        $orderId = $booked['order']['id'];
        app(PaymentService::class)->markPaid(Payment::where('reference', $booked['payment']['reference'])->firstOrFail(), 'pay_1', 'mada');

        // Flexible policy, five days ahead: the whole amount comes back.
        $refundId = $this->postJson("/api/v1/orders/{$orderId}/refund", ['reason' => 'Change of plans'])->assertCreated()->json('data.id');
        app(RefundService::class)->approve(Refund::findOrFail($refundId), $this->admin());

        $this->assertSame('refunded', Order::findOrFail($orderId)->status->value);
        $this->assertSame(0, BookingSlot::count());
        $this->assertSame(1, $customer->notifications()->where('data->key', 'refund_to_wallet')->count());

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/wallet')->assertOk()
            ->assertJsonPath('data.balance', 100)
            ->assertJsonPath('data.transactions.0.type.value', 'refund')
            ->assertJsonPath('data.transactions.0.amount', 100)
            ->assertJsonPath('data.transactions.0.order_id', $orderId);

        // The refunded money pays the next booking, and a refund of that one comes back to the wallet too.
        $next = $this->book($customer, 1, 6);
        $this->postJson("/api/v1/orders/{$next['order']['id']}/pay-wallet")->assertOk()->assertJsonPath('data.wallet_balance', 0);

        $again = $this->postJson("/api/v1/orders/{$next['order']['id']}/refund", ['reason' => 'Again'])->assertCreated()->json('data.id');
        app(RefundService::class)->approve(Refund::findOrFail($again), $this->admin());
        $this->assertSame(100.0, app(WalletService::class)->balance($customer));
        $this->assertSame(3, WalletTransaction::where('user_id', $customer->id)->count()); // refund, payment, refund
    }
}
