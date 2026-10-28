<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\PlanItem;
use App\Models\ProviderSubscription;
use App\Models\SubscriptionFeature;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The provider's plan. Every provider is on the free plan until a paid one runs. Asking for a paid plan only
 * sends a request: activating it is an admin decision, and every feature is enforced by the server
 * (SubscriptionService), whatever this API is sent.
 */
class PlanController extends ProviderController
{
    public function plans(): JsonResponse
    {
        $plans = Plan::active()->with('items')->orderByDesc('is_free')->orderBy('sort_order')->orderBy('price')->get();

        return response()->json(['data' => $plans->map(fn (Plan $plan) => self::plan($plan))->values()]);
    }

    public function subscription(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $provider = $this->provider($request);
        $current = $subscriptions->current($provider);
        $pending = $provider->subscriptions()->where('status', SubscriptionStatus::Pending)->with('plan')->latest('id')->first();
        $free = Plan::free();

        return response()->json(['data' => [
            'current' => $current ? self::subscriptionRow($current) + [
                'features' => $current->features->map(fn (SubscriptionFeature $f) => [
                    'key' => $f->feature->value,
                    'label' => $f->feature->getLabel(),
                    'value' => $f->value,
                    'starts_at' => $f->starts_at->toIso8601String(),
                    'ends_at' => $f->ends_at->toIso8601String(),
                    'is_running' => $f->isRunning(),
                ])->values(),
            ] : null,
            // Without a running paid plan the provider is on this one.
            'free_plan' => $free ? self::plan($free) : null,
            'pending' => $pending ? self::subscriptionRow($pending) : null,
            'history' => $provider->subscriptions()->with('plan')->latest('id')->limit(20)->get()
                ->map(fn (ProviderSubscription $s) => self::subscriptionRow($s))->values(),
        ]]);
    }

    public function requestPlan(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'integer', Rule::exists('plans', 'id')]]);
        $subscription = $subscriptions->request($this->provider($request), Plan::findOrFail($data['plan_id']), $request->user());

        return response()->json(['message' => __('api.plans.requested'), 'data' => self::subscriptionRow($subscription->load('plan'))], 201);
    }

    public function cancelRequest(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $pending = $this->provider($request)->subscriptions()->where('status', SubscriptionStatus::Pending)->firstOrFail();
        $subscriptions->cancel($pending, $request->user());

        return response()->json(['message' => __('api.plans.request_cancelled')]);
    }

    public static function plan(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->tr('name'),
            'description' => $plan->tr('description'),
            'is_free' => $plan->is_free,
            'price' => $plan->price,
            'currency' => config('farah.currency'),
            'duration_days' => $plan->is_free ? null : $plan->duration_days,
            'features' => $plan->items->map(fn (PlanItem $item) => [
                'key' => $item->feature->value,
                'label' => $item->feature->getLabel(),
                'value' => $item->value,
                'days' => $plan->is_free ? null : ($item->days ?? $plan->duration_days),
            ])->values(),
        ];
    }

    private static function subscriptionRow(ProviderSubscription $s): array
    {
        return [
            'id' => $s->id,
            'plan' => ['id' => $s->plan->id, 'name' => $s->plan->tr('name')],
            'status' => $s->isExpired()
                ? ['value' => 'expired', 'label' => __('enums.subscription_status.expired'), 'color' => 'gray']
                : $s->status->toArray(),
            'price' => $s->price,
            'duration_days' => $s->duration_days,
            'starts_at' => $s->starts_at?->toIso8601String(),
            'ends_at' => $s->ends_at?->toIso8601String(),
            'days_left' => $s->daysLeft(),
            'note' => $s->note,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
