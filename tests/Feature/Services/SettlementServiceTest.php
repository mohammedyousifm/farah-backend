<?php

namespace Tests\Feature\Services;

use App\Enums\SettlementStatus;
use App\Models\LedgerEntry;
use App\Models\Setting;
use App\Services\LedgerService;
use App\Services\OrderService;
use App\Services\RefundService;
use App\Services\SettlementService;

class SettlementServiceTest extends ServiceTestCase
{
    private function settlements(): SettlementService
    {
        return app(SettlementService::class);
    }

    public function test_funds_are_held_until_the_booking_ends_plus_hold_days(): void
    {
        $provider = $this->provider();
        $provider->update(['iban' => 'SA0380000000608010167519', 'bank_name' => 'Bank']);
        $order = $this->paidOrder($this->venue($provider), 2); // 20:00 -> 22:00 in 3 days, hold 3 days
        $ends = $order->bookings->first()->ends_at;

        $this->assertTrue($ends->copy()->addDays(3)->equalTo(LedgerEntry::where('order_id', $order->id)->where('type', 'sale')->first()->available_at));
        $this->assertBusiness('nothing_to_settle', fn () => $this->settlements()->generate($provider, now()));
        $this->assertEquals(180.0, app(LedgerService::class)->balances($provider)['pending']);

        // Played, but still inside the hold period.
        $this->travel(5)->days();
        $this->assertBusiness('nothing_to_settle', fn () => $this->settlements()->generate($provider, now()));
        $this->assertEquals(0.0, app(LedgerService::class)->balances($provider)['available']);

        $this->travel(2)->days();
        $settlement = $this->settlements()->generate($provider, now());

        $this->assertEquals(200.0, $settlement->gross_amount);
        $this->assertEquals(20.0, $settlement->commission_amount);
        $this->assertEquals(180.0, $settlement->net_amount);
        $this->assertSame(SettlementStatus::PendingApproval, $settlement->status);
        $this->assertSame(2, LedgerEntry::where('settlement_id', $settlement->id)->count());
        $this->assertEquals(180.0, app(LedgerService::class)->balances($provider)['in_settlement']);
    }

    public function test_hold_runs_from_the_last_booking_of_the_order(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $order = $this->paidLines([
            $this->line($venue, 1, $this->slot(1)),
            $this->line($venue, 2, $this->slot(8, '18:00')), // ends 20:00 in 8 days
        ]);

        $lastEnd = $order->bookings->max('ends_at');
        $this->assertTrue($this->slot(8, '20:00')->equalTo($lastEnd));
        foreach (LedgerEntry::where('order_id', $order->id)->get() as $entry) {
            $this->assertTrue($lastEnd->copy()->addDays(3)->equalTo($entry->available_at));
        }

        // The first booking was played long ago, the second one is not over yet.
        $this->travel(7)->days();
        $this->assertBusiness('nothing_to_settle', fn () => $this->settlements()->generate($provider, now(), null, false));
        $this->travel(5)->days();
        $this->assertEquals(270.0, $this->settlements()->generate($provider, now(), null, false)->net_amount);
    }

    public function test_counter_booking_commission_is_deducted_from_the_next_settlement(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $this->paidOrder($venue, 2); // +180 net
        app(OrderService::class)->createOffline($provider, [$this->line($venue, 1, $this->slot(2))], ['name' => 'Walk-in'], 'cash', $provider->owner); // owes 10

        $this->travel(14)->days();
        $settlement = $this->settlements()->generate($provider, now(), null, false);

        $this->assertEquals(200.0, $settlement->gross_amount); // cash sales never pass through the platform
        $this->assertEquals(30.0, $settlement->commission_amount);
        $this->assertEquals(170.0, $settlement->net_amount);
    }

    public function test_minimum_amount_and_no_double_settling(): void
    {
        $provider = $this->provider();
        $provider->update(['iban' => 'SA0380000000608010167519']);
        Setting::updateOrCreate(['key' => 'settlement_min_amount'], ['value' => 500]);
        $this->paidOrder($this->venue($provider), 2);
        $this->travel(14)->days();

        $this->assertBusiness('below_minimum', fn () => $this->settlements()->generate($provider, now()));
        $settlement = $this->settlements()->generate($provider, now(), null, false); // admin override

        // Entries already taken are never settled again.
        $this->assertBusiness('nothing_to_settle', fn () => $this->settlements()->generate($provider, now(), null, false));
        [$created, $skipped] = $this->settlements()->generateAll(now());
        $this->assertSame([0, 1], [$created, $skipped]);

        // Rejecting releases them for the next cycle.
        $admin = $this->admin();
        $this->settlements()->reject($settlement, $admin, 'wrong period');
        $again = $this->settlements()->generate($provider, now(), null, false);
        $this->assertEquals(180.0, $again->net_amount);
        $this->assertBusiness('wrong_status', fn () => $this->settlements()->reject($settlement->fresh(), $admin, 'twice'));
    }

    public function test_payout_is_written_once_and_requires_iban(): void
    {
        $provider = $this->provider();
        $this->paidOrder($this->venue($provider), 2);
        $this->travel(14)->days();
        $admin = $this->admin();

        $noIban = $this->settlements()->generate($provider, now(), null, false);
        $this->assertBusiness('missing_iban', fn () => $this->settlements()->approve($noIban, $admin));
        $this->settlements()->reject($noIban, $admin, 'no iban');

        $provider->update(['iban' => 'SA0380000000608010167519']);
        $settlement = $this->settlements()->generate($provider->fresh(), now(), null, false);
        $this->assertBusiness('wrong_status', fn () => $this->settlements()->markPaid($settlement, $admin, 'TRX-1'));
        $this->settlements()->approve($settlement, $admin);
        $this->settlements()->markPaid($settlement, $admin, 'TRX-1');
        $this->assertBusiness('wrong_status', fn () => $this->settlements()->markPaid($settlement->fresh(), $admin, 'TRX-1'));

        $this->assertSame(1, LedgerEntry::where('settlement_id', $settlement->id)->where('type', 'payout')->count());
        $balances = app(LedgerService::class)->balances($provider);
        $this->assertEquals(180.0, $balances['paid_out']);
        $this->assertEquals(0.0, $balances['available']);
        $this->assertEquals(0.0, $balances['in_settlement']);
    }

    public function test_refund_after_payout_is_carried_to_the_next_settlement(): void
    {
        $provider = $this->provider();
        $provider->update(['iban' => 'SA0380000000608010167519']);
        $admin = $this->admin();
        $order = $this->paidOrder($this->venue($provider), 2);
        $this->travel(14)->days();
        $settlement = $this->settlements()->generate($provider, now(), null, false);
        $this->settlements()->approve($settlement, $admin);
        $this->settlements()->markPaid($settlement, $admin, 'TRX-1');

        app(RefundService::class)->createByAdmin($order->fresh(), 100, null, 'goodwill', $admin);

        // Provider now owes 100 - 10 commission back = 90; nothing positive to pay out.
        $this->assertEquals(-90.0, app(LedgerService::class)->balances($provider)['available']);
        $this->travel(1)->days();
        $this->assertBusiness('below_minimum', fn () => $this->settlements()->generate($provider, now(), null, false));
    }
}
