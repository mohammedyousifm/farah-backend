<?php

namespace Tests\Feature\Services;

use App\Models\Category;
use App\Models\CommissionRule;
use App\Models\Order;
use App\Models\Sector;
use App\Models\Venue;
use App\Services\CommissionService;
use App\Services\OrderService;

class CommissionServiceTest extends ServiceTestCase
{
    private function rule(string $scope, ?int $scopeId, float $rate, array $extra = []): CommissionRule
    {
        return CommissionRule::create($extra + [
            'name' => "{$scope} rule",
            'scope' => $scope,
            'scope_id' => $scopeId,
            'type' => 'percentage',
            'rate' => $rate,
            'effective_from' => now()->subMonth(),
        ]);
    }

    /** Unpaid order for $slots hours; the commission is worked out from its booked activity. */
    private function order(Venue $venue, int $slots = 2): Order
    {
        return app(OrderService::class)->createOnline(
            $this->customer('+9665'.random_int(10000000, 99999999)),
            [$this->line($venue, $slots)],
        );
    }

    public function test_most_specific_rule_wins(): void
    {
        $provider = $this->provider();
        $category = Category::where('slug', 'snooker')->firstOrFail();
        $venue = $this->venue($provider, [], ['category_id' => $category->id]);
        $order = $this->order($venue); // 200 SAR of billiards / snooker
        $service = app(CommissionService::class);

        $this->assertEquals(10.0, $service->calculate($order)['rate']); // global

        $this->rule('sector', $this->sector->id, 8);
        $this->assertEquals(8.0, $service->calculate($order)['rate']);

        $this->rule('category', $category->id, 6);
        $this->assertEquals(6.0, $service->calculate($order)['rate']);

        $this->rule('provider', $provider->id, 4);
        $this->assertEquals(8.0, $service->calculate($order)['amount']);

        // Inactive or not-yet-effective rules are ignored.
        $this->rule('provider', $provider->id, 1, ['version' => 2, 'effective_from' => now()->addDay()]);
        $this->rule('provider', $provider->id, 2, ['version' => 3, 'is_active' => false]);
        $this->assertEquals(4.0, $service->calculate($order)['rate']);
    }

    public function test_sector_and_category_come_from_the_booked_activity(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider); // billiards, no category
        $bowling = Sector::where('slug', 'bowling')->firstOrFail();
        $pool = Category::where('slug', 'pool')->firstOrFail();
        $lanes = $venue->activities()->create(['sector_id' => $bowling->id, 'name_ar' => 'بولينج', 'name_en' => 'Bowling',
            'price_per_hour' => 150, 'sort_order' => 1]);
        $lanes->resources()->create(['name_ar' => 'مسار 1', 'name_en' => 'Lane 1']);
        $this->rule('sector', $bowling->id, 12);
        $this->rule('category', $pool->id, 5); // a billiards category this venue's activity is not in
        $service = app(CommissionService::class);
        $line = fn (int $activityId, int $days = 3) => ['activity_id' => $activityId, 'starts_at' => $this->slot($days)->toIso8601String(), 'slots' => 1];

        $bowlingOrder = app(OrderService::class)->createOnline($this->customer(), [$line($lanes->id)]);
        $billiardsOrder = app(OrderService::class)->createOnline($this->customer('+966502222222'), [$line($this->activity($venue)->id)]);

        // Same venue, same provider: the rate follows what was booked.
        $this->assertEquals(12.0, $service->calculate($bowlingOrder)['rate']);
        $this->assertEquals(18.0, $service->calculate($bowlingOrder)['amount']);
        $this->assertEquals(10.0, $service->calculate($billiardsOrder)['rate']);

        // The snapshot taken at payment uses the same rule.
        $paid = $this->paidLines([$line($lanes->id, 4)]);
        $this->assertEquals(12.0, $paid->commission_rate);
        $this->assertEquals(132.0, $paid->provider_net);
    }

    public function test_fixed_and_combined_commission_never_exceed_the_total(): void
    {
        $provider = $this->provider();
        $this->rule('provider', $provider->id, 7.5, ['type' => 'percentage_plus_fixed', 'fixed_amount' => 2]);
        $service = app(CommissionService::class);

        $this->assertEquals(17.0, $service->calculate($this->order($this->venue($provider)))['amount']);
        $this->assertEquals(1.0, $service->calculate($this->order($this->venue($provider, [], ['price_per_hour' => 1]), 1))['amount']); // capped
        $this->assertEquals(0.0, $service->calculate($this->order($this->venue($provider, [], ['price_per_hour' => 0]), 1))['amount']);

        $fixed = $this->provider(null, 'Fixed');
        $this->rule('provider', $fixed->id, 0, ['type' => 'fixed', 'fixed_amount' => 5]);
        $result = $service->calculate($this->order($this->venue($fixed)));
        $this->assertEquals([0.0, 5.0, 5.0], [$result['rate'], $result['fixed'], $result['amount']]);
    }

    public function test_versioning_keeps_history_and_order_snapshots(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $global = CommissionRule::where('scope', 'global')->firstOrFail();
        $before = $this->paidOrder($venue, 1);

        $v2 = app(CommissionService::class)->newVersion($global, ['rate' => 12, 'scope' => 'provider'], now());
        $this->assertSame(2, $v2->version);
        $this->assertSame('global', $v2->scope); // scope cannot be changed by a new version
        $this->assertNotNull($global->fresh()->effective_to);

        $after = $this->paidOrder($venue, 1, null, $this->slot(4));
        $this->assertEquals(10.0, $before->fresh()->commission_amount);
        $this->assertEquals(12.0, $after->commission_amount);
        $this->assertSame($v2->id, $after->commission_rule_id);

        // Past moments still resolve to the old version.
        $this->assertSame($global->id, app(CommissionService::class)->resolve($provider->id, null, $this->sector->id, now()->subDay())->id);
        $this->assertEquals(10.0, app(CommissionService::class)->calculate($after, now()->subDay())['rate']);

        // A closed version cannot be versioned again.
        $this->assertBusiness('rule_already_closed', fn () => app(CommissionService::class)->newVersion($global->fresh(), ['rate' => 5], now()->addDay()));
        $this->assertBusiness('invalid_effective_from', fn () => app(CommissionService::class)->newVersion($v2, ['rate' => 5], now()->subYear()));
    }
}
