<?php

namespace Tests\Feature\Api;

use App\Enums\PlanFeature;
use App\Enums\ProviderStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\BusinessException;
use App\Filament\Provider\Pages\Auth\RegisterProvider;
use App\Filament\Provider\Pages\Dashboard;
use App\Filament\Provider\Pages\MyPlan;
use App\Models\HomeSection;
use App\Models\Plan;
use App\Models\User;
use App\Services\HomeFeedService;
use App\Services\SubscriptionService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Feature\Provider\ProviderPanelTestCase;

/**
 * Provider plans, enforced by the server: everyone is on the free plan, a paid plan's features each run on
 * their own dates, and a provider can only ask for a plan (from sign-up on), never switch one on.
 */
class PlanTest extends ProviderPanelTestCase
{
    private function plan(array $items, int $days = 30, float $price = 499, bool $free = false): Plan
    {
        $plan = Plan::create(['name_ar' => $free ? 'المجانية' : 'احترافية', 'name_en' => $free ? 'Free' : 'Pro', 'price' => $price, 'duration_days' => $days, 'is_free' => $free]);
        foreach ($items as [$feature, $value, $featureDays]) {
            $plan->items()->create(['feature' => $feature, 'value' => $value, 'days' => $featureDays]);
        }

        return $plan;
    }

    public function test_a_feature_stops_on_its_own_date_while_the_subscription_runs_on(): void
    {
        $plan = $this->plan([[PlanFeature::SearchBoost, null, 7], [PlanFeature::HomeFeatured, null, null]]);
        $service = app(SubscriptionService::class);

        $subscription = $service->request($this->provider, $plan, $this->owner);
        $this->assertSame(SubscriptionStatus::Pending, $subscription->status);
        $this->assertFalse($service->has($this->provider, PlanFeature::SearchBoost), 'a request grants nothing');

        $service->activate($subscription, $this->admin, 'TRX-1');
        $this->assertTrue($service->has($this->provider, PlanFeature::SearchBoost));
        $this->assertTrue($service->has($this->provider, PlanFeature::HomeFeatured));
        $this->assertSame(1, $this->owner->notifications()->where('data->key', 'subscription_activated')->count());

        $this->travel(8)->days();
        $this->assertNotNull($service->current($this->provider), 'the subscription is still running');
        $this->assertFalse($service->has($this->provider, PlanFeature::SearchBoost), 'the 7-day feature has ended');
        $this->assertTrue($service->has($this->provider, PlanFeature::HomeFeatured));

        $this->travel(23)->days();
        $this->assertNull($service->current($this->provider));
        $this->assertFalse($service->has($this->provider, PlanFeature::HomeFeatured));
    }

    public function test_a_new_plan_replaces_the_running_one_and_a_stopped_plan_grants_nothing(): void
    {
        $service = app(SubscriptionService::class);
        $first = $service->assign($this->provider, $this->plan([[PlanFeature::HomeFeatured, null, null]]), $this->admin);
        $second = $service->assign($this->provider, $this->plan([[PlanFeature::SearchBoost, null, null]]), $this->admin);

        $this->assertSame(SubscriptionStatus::Cancelled, $first->fresh()->status);
        $this->assertFalse($service->has($this->provider, PlanFeature::HomeFeatured));
        $this->assertTrue($service->has($this->provider, PlanFeature::SearchBoost));

        $service->cancel($second, $this->admin, 'refund');
        $this->assertFalse($service->has($this->provider, PlanFeature::SearchBoost));
        $this->assertNull($service->current($this->provider));
    }

    public function test_the_provider_api_can_only_ask_for_a_paid_plan_never_switch_it_on(): void
    {
        $free = $this->plan([], price: 0, free: true);
        $plan = $this->plan([[PlanFeature::SearchBoost, null, null]]);
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/provider/plans')->assertOk()
            ->assertJsonPath('data.0.is_free', true)
            ->assertJsonPath('data.1.features.0.key', 'search_boost');
        $this->postJson('/api/v1/provider/subscription', ['plan_id' => $free->id])->assertStatus(422)->assertJsonPath('code', 'free_plan');
        $this->postJson('/api/v1/provider/subscription', ['plan_id' => $plan->id])->assertCreated()->assertJsonPath('data.status.value', 'pending');
        $this->postJson('/api/v1/provider/subscription', ['plan_id' => $plan->id])->assertStatus(422)->assertJsonPath('code', 'subscription_pending');

        // Nothing the provider sends turns the feature on: there is no such endpoint and the request stays pending.
        $this->putJson('/api/v1/provider/subscription', ['status' => 'active'])->assertStatus(405);
        $this->assertFalse(app(SubscriptionService::class)->has($this->provider, PlanFeature::SearchBoost));
        $this->getJson('/api/v1/provider/subscription')->assertOk()
            ->assertJsonPath('data.current', null)
            ->assertJsonPath('data.free_plan.id', $free->id)
            ->assertJsonPath('data.pending.plan.id', $plan->id);

        $this->deleteJson('/api/v1/provider/subscription/pending')->assertOk();
        $this->assertSame(SubscriptionStatus::Cancelled, $this->provider->subscriptions()->first()->status);
    }

    public function test_no_plan_limits_the_number_of_activities(): void
    {
        Sanctum::actingAs($this->owner);
        $url = "/api/v1/provider/venues/{$this->venue->id}/activities";
        $body = ['sector_id' => $this->sector->id, 'name_ar' => 'سنوكر', 'price_per_hour' => 120];

        $this->plan([], price: 0, free: true);
        foreach (range(1, 6) as $i) {
            $this->postJson($url, $body)->assertCreated();
        }
    }

    public function test_the_moving_banner_carries_a_slide_per_plan_store(): void
    {
        $other = $this->provider(null, 'Other');
        $planned = $this->venue($other, ['cover_image' => 'venues/cover.jpg']);
        $this->venue->update(['cover_image' => 'venues/mine.jpg']);
        $banner = HomeSection::create(['type' => 'banner', 'layout' => 'hero', 'source' => 'manual', 'is_active' => true, 'sort_order' => 0]);
        $banner->items()->create(['title_ar' => 'عرض', 'media_type' => 'image', 'media_url' => 'https://example.com/b.jpg']);

        // The guest feed is cached for a few minutes; each look here is a fresh one.
        $slides = function () {
            Cache::flush();

            return collect($this->getJson('/api/v1/home')->assertOk()->json('data.0.items'));
        };
        $this->assertCount(1, $slides());

        app(SubscriptionService::class)->assign($other, $this->plan([[PlanFeature::HomeSlider, null, null]]), $this->admin);
        $this->assertSame(['venue', (string) $planned->id], [$slides()->last()['link']['type'], (string) $slides()->last()['link']['value']]);
        $this->assertCount(2, $slides());

        // No banner set up by the admins: one is opened for the plan stores.
        $banner->delete();
        Cache::flush();
        $this->getJson('/api/v1/home')->assertJsonPath('data.0.type', 'banner')->assertJsonPath('data.0.items.0.link.value', (string) $planned->id);
    }

    public function test_recommendations_list_plan_stores_first(): void
    {
        $other = $this->provider(null, 'Other');
        $planned = $this->venue($other, ['rating_avg' => 1]);
        $this->venue->update(['is_featured' => true, 'rating_avg' => 5]);

        $first = fn () => $this->getJson('/api/v1/recommendations?limit=3')->assertOk()->json('data.0.id');
        $this->assertSame($this->venue->id, $first());

        app(SubscriptionService::class)->assign($other, $this->plan([[PlanFeature::Recommended, null, null]]), $this->admin);
        $this->assertSame($planned->id, $first());
    }

    public function test_only_stores_with_the_seo_feature_get_a_public_page_and_a_sitemap_entry(): void
    {
        $other = $this->provider(null, 'Other');
        $planned = $this->venue($other, ['summary_ar' => 'أفضل صالة بلياردو']);

        $this->get('/places/'.$planned->slug)->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/places/', false);

        app(SubscriptionService::class)->assign($other, $this->plan([[PlanFeature::Seo, null, null]]), $this->admin);
        $this->get('/places/'.$planned->slug.'?lang=ar')->assertOk()
            ->assertSee($planned->name_ar)->assertSee('أفضل صالة بلياردو')
            ->assertSee('"@type":"SportsActivityLocation"', false);
        $this->get('/places/'.$this->venue->slug)->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertSee('/places/'.$planned->slug, false)->assertDontSee('/places/'.$this->venue->slug, false);
    }

    public function test_there_is_one_free_plan_and_it_costs_nothing(): void
    {
        $first = $this->plan([], price: 50, free: true);
        $second = $this->plan([], price: 70, free: true);

        $this->assertSame(0.0, $second->fresh()->price);
        $this->assertFalse($first->fresh()->is_free);
        $this->assertSame($second->id, Plan::free()->id);
    }

    public function test_search_puts_plan_boosted_stores_first_until_the_feature_ends(): void
    {
        $other = $this->provider(null, 'Other');
        $boosted = $this->venue($other);

        $ids = fn () => collect($this->getJson('/api/v1/venues')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$this->venue->id, $boosted->id], $ids());

        app(SubscriptionService::class)->assign($other, $this->plan([[PlanFeature::SearchBoost, null, 3]]), $this->admin);
        $this->assertSame([$boosted->id, $this->venue->id], $ids());
        $this->assertSame([$this->venue->id, $boosted->id], collect($this->getJson('/api/v1/venues?sort=latest')->json('data'))->pluck('id')->sort()->values()->all());

        $this->travel(4)->days();
        $this->assertSame([$this->venue->id, $boosted->id], $ids());
    }

    public function test_home_featured_rows_carry_plan_stores(): void
    {
        $other = $this->provider(null, 'Other');
        $planned = $this->venue($other);
        app(SubscriptionService::class)->assign($other, $this->plan([[PlanFeature::HomeFeatured, null, null]]), $this->admin);

        $section = HomeSection::create([
            'title_ar' => 'مميز', 'type' => 'venues', 'source' => 'featured', 'max_items' => 10, 'is_active' => true, 'sort_order' => 1,
        ]);
        $items = app(HomeFeedService::class)->resolve($section, null, $this->city->id);

        $this->assertSame([$planned->id], $items->pluck('id')->all());
    }

    public function test_a_new_provider_sees_the_plans_from_the_start_and_its_plan_waits_for_approval(): void
    {
        $this->plan([], price: 0, free: true);
        $plan = $this->plan([[PlanFeature::SearchBoost, null, null]]);
        $this->provider->update(['status' => ProviderStatus::Pending]);
        $this->asOwner();

        $this->get(MyPlan::getUrl())->assertOk()->assertSee('المجانية')->assertSee('احترافية');
        $this->get(Dashboard::getUrl())->assertOk()->assertSee(__('provider.onboarding.step_plan'));

        $subscription = app(SubscriptionService::class)->request($this->provider->fresh(), $plan, $this->owner);
        try {
            app(SubscriptionService::class)->activate($subscription, $this->admin);
            $this->fail('a plan must not start before the provider is approved');
        } catch (BusinessException $e) {
            $this->assertSame('provider_not_approved', $e->errorCode);
        }

        $this->provider->update(['status' => ProviderStatus::Approved]);
        app(SubscriptionService::class)->activate($subscription->fresh(), $this->admin);
        $this->assertTrue(app(SubscriptionService::class)->has($this->provider, PlanFeature::SearchBoost));
    }

    public function test_the_website_lists_the_plans_with_a_sign_up_link_for_each(): void
    {
        $free = $this->plan([], price: 0, free: true);
        $paid = $this->plan([[PlanFeature::SearchBoost, null, null]]);
        Plan::create(['name_ar' => 'قديمة', 'price' => 10, 'duration_days' => 30, 'is_active' => false]);

        $this->get('/')->assertOk()
            ->assertSee('id="plans"', false)
            ->assertSee('المجانية')->assertSee('احترافية')->assertDontSee('قديمة')
            ->assertSee('/provider/register?plan='.$free->id, false)
            ->assertSee('/provider/register?plan='.$paid->id, false);
    }

    public function test_a_plan_chosen_at_sign_up_is_preselected_and_becomes_a_request(): void
    {
        $this->plan([], price: 0, free: true);
        $paid = $this->plan([[PlanFeature::SearchBoost, null, null]]);
        Filament::setCurrentPanel(Filament::getPanel('provider'));

        $this->get('/provider/register?plan='.$paid->id)->assertOk()->assertSee(__('provider.register.plan'));

        Livewire::withQueryParams(['plan' => $paid->id])
            ->test(RegisterProvider::class)
            ->assertFormSet(['plan_id' => $paid->id])
            ->fillForm([
                'name' => 'New Owner', 'email' => 'new.owner@example.com', 'phone' => '0551234567',
                'password' => 'secret-pass-123', 'passwordConfirmation' => 'secret-pass-123',
                'name_ar' => 'منشأة جديدة', 'cr_number' => '1010101010', 'maps_url' => 'https://maps.app.goo.gl/AbCdEf123456',
                'city_id' => $this->city->id, 'sector_ids' => [$this->sector->id],
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $provider = User::where('email', 'new.owner@example.com')->firstOrFail()->ownedProvider;
        $request = $provider->subscriptions()->firstOrFail();
        $this->assertSame([$paid->id, SubscriptionStatus::Pending], [$request->plan_id, $request->status]);
        $this->assertFalse(app(SubscriptionService::class)->has($provider, PlanFeature::SearchBoost), 'it waits for approval and payment');
    }

    public function test_my_plan_page_renders_with_a_running_plan(): void
    {
        $this->plan([], price: 0, free: true);
        app(SubscriptionService::class)->assign($this->provider, $this->plan([[PlanFeature::SearchBoost, null, 7]]), $this->admin);
        $this->asOwner();

        $this->get(MyPlan::getUrl())->assertOk()->assertSee('احترافية')->assertSee(__('provider.plans.free_returns'));
    }
}
