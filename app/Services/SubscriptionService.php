<?php

namespace App\Services;

use App\Enums\PlanFeature;
use App\Enums\ProviderStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserType;
use App\Exceptions\BusinessException;
use App\Models\Plan;
use App\Models\PlanItem;
use App\Models\Provider;
use App\Models\ProviderSubscription;
use App\Models\SubscriptionFeature;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\AdminNotifier;
use App\Support\Once;
use Illuminate\Support\Facades\DB;

/**
 * Provider plans. Every provider is on the free plan (Plan::free()) until a paid plan runs. A provider asks
 * for a paid plan (pending), from the first day of sign-up on; an admin activates it once it is paid and the
 * provider is approved. On activation the plan's features are copied to subscription_features, each with its
 * own end date, so a feature can stop before the subscription does. Everything that depends on a feature asks
 * has() here: hiding a button in a dashboard is never the check.
 */
class SubscriptionService
{
    public function request(Provider $provider, Plan $plan, ?User $by): ProviderSubscription
    {
        if (! in_array($provider->status, [ProviderStatus::Pending, ProviderStatus::Approved], true)) {
            throw new BusinessException(__('api.plans.provider_not_allowed'), 'provider_not_allowed', 403);
        }
        if (! $plan->is_active) {
            throw new BusinessException(__('api.plans.plan_inactive'), 'plan_inactive');
        }
        if ($plan->is_free) {
            throw new BusinessException(__('api.plans.free_is_automatic'), 'free_plan');
        }
        if ($provider->subscriptions()->where('status', SubscriptionStatus::Pending)->exists()) {
            throw new BusinessException(__('api.plans.already_pending'), 'subscription_pending');
        }

        $subscription = $provider->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Pending,
            'price' => $plan->price,
            'duration_days' => $plan->duration_days,
            'requested_by' => $by?->id,
        ]);

        AdminNotifier::send(['finance'], 'subscription_requested', ['provider' => $provider->name_ar, 'plan' => $plan->name_ar], ['subscription_id' => $subscription->id]);

        return $subscription;
    }

    /** An admin gives a plan straight away (paid outside the platform, a gift, a trial). */
    public function assign(Provider $provider, Plan $plan, User $by, ?string $paymentReference = null): ProviderSubscription
    {
        $subscription = $provider->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Pending,
            'price' => $plan->price,
            'duration_days' => $plan->duration_days,
            'requested_by' => $by->id,
        ]);
        $this->activate($subscription, $by, $paymentReference);

        return $subscription->fresh();
    }

    /**
     * Starts the subscription now. A plan that is running already is closed first: moving to another plan
     * (an upgrade) replaces it, it does not add to it.
     */
    public function activate(ProviderSubscription $subscription, User $by, ?string $paymentReference = null): void
    {
        // A plan's days count from the moment the store can use them: approve the provider first.
        if (! $subscription->provider->isApproved()) {
            throw new BusinessException(__('api.plans.approve_provider_first'), 'provider_not_approved');
        }
        if ($subscription->status !== SubscriptionStatus::Pending || ! Once::first($subscription, 'status', SubscriptionStatus::Active)) {
            return;
        }

        DB::transaction(function () use ($subscription, $by, $paymentReference) {
            ProviderSubscription::where('provider_id', $subscription->provider_id)
                ->where('status', SubscriptionStatus::Active)
                ->whereKeyNot($subscription->id)
                ->get()
                ->each(fn (ProviderSubscription $old) => $this->close($old));

            $starts = now();
            $ends = $starts->copy()->addDays($subscription->duration_days);

            $subscription->update([
                'status' => SubscriptionStatus::Active,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'payment_reference' => $paymentReference,
                'decided_by' => $by->id,
                'decided_at' => now(),
            ]);

            foreach ($subscription->plan->items as $item) {
                $featureEnds = $item->days ? $starts->copy()->addDays($item->days) : $ends;

                $subscription->features()->create([
                    'provider_id' => $subscription->provider_id,
                    'feature' => $item->feature,
                    'value' => $item->feature->hasValue() ? $item->value : null,
                    'starts_at' => $starts,
                    'ends_at' => $featureEnds->min($ends),
                ]);
            }
        });

        SystemNotification::send($subscription->provider->owner, 'subscription_activated', [
            'plan' => $subscription->plan->name_ar,
            'date' => $subscription->ends_at->timezone(config('farah.timezone'))->format('Y-m-d'),
        ], ['subscription_id' => $subscription->id]);
    }

    public function reject(ProviderSubscription $subscription, User $by, string $reason): void
    {
        if ($subscription->status !== SubscriptionStatus::Pending || ! Once::first($subscription, 'status', SubscriptionStatus::Rejected)) {
            return;
        }

        $subscription->update(['status' => SubscriptionStatus::Rejected, 'note' => $reason, 'decided_by' => $by->id, 'decided_at' => now()]);
        SystemNotification::send($subscription->provider->owner, 'subscription_rejected', ['plan' => $subscription->plan->name_ar, 'reason' => $reason], ['subscription_id' => $subscription->id]);
    }

    /** The provider withdraws its own request, or an admin stops a request or a running subscription. */
    public function cancel(ProviderSubscription $subscription, ?User $by = null, ?string $reason = null): void
    {
        if (! in_array($subscription->status, [SubscriptionStatus::Pending, SubscriptionStatus::Active], true)
            || ! Once::first($subscription, 'status', SubscriptionStatus::Cancelled)) {
            return;
        }

        $wasActive = $subscription->status === SubscriptionStatus::Active;
        DB::transaction(fn () => $this->close($subscription, $reason, $by));

        if ($wasActive && $by?->type === UserType::Admin) {
            SystemNotification::send($subscription->provider->owner, 'subscription_cancelled', ['plan' => $subscription->plan->name_ar, 'reason' => $reason ?? '-'], ['subscription_id' => $subscription->id]);
        }
    }

    /** The running subscription (active and inside its dates), if any. */
    public function current(Provider $provider): ?ProviderSubscription
    {
        return $provider->subscriptions()
            ->where('status', SubscriptionStatus::Active)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->with(['plan', 'features'])
            ->latest('starts_at')
            ->first();
    }

    /**
     * Does the provider hold this feature right now? Through the free plan, which everyone is on, or through a
     * paid plan's feature, checked on its dates so it stops by itself.
     */
    public function has(Provider|int $provider, PlanFeature $feature): bool
    {
        return (bool) $this->freeItem($feature)
            || $this->running($provider instanceof Provider ? $provider->id : $provider, $feature)->exists();
    }

    /** The free plan's row for this feature, if the free plan includes it (memoized per request). */
    public function freeItem(PlanFeature $feature): ?PlanItem
    {
        return once(fn () => Plan::free())?->items->first(fn (PlanItem $item) => $item->feature === $feature);
    }

    /**
     * Providers holding the feature through a paid plan right now, for ordering lists (memoized per request).
     * When the free plan has it too, everyone does and nobody goes first: the list is empty.
     *
     * @return array<int, int>
     */
    public function providerIdsWith(PlanFeature $feature): array
    {
        if ($this->freeItem($feature)) {
            return [];
        }

        return once(fn () => SubscriptionFeature::query()
            ->where('feature', $feature)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->whereHas('subscription', fn ($q) => $q->where('status', SubscriptionStatus::Active))
            ->distinct()
            ->pluck('provider_id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    private function running(int $providerId, PlanFeature $feature)
    {
        return SubscriptionFeature::query()
            ->where('provider_id', $providerId)
            ->where('feature', $feature)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->whereHas('subscription', fn ($q) => $q->where('status', SubscriptionStatus::Active));
    }

    /** Cancelled: its features stop now (they keep their history for reports). */
    private function close(ProviderSubscription $subscription, ?string $reason = null, ?User $by = null): void
    {
        $subscription->features()->where('ends_at', '>', now())->update(['ends_at' => now()]);
        $subscription->update(array_filter([
            'status' => SubscriptionStatus::Cancelled,
            'ends_at' => $subscription->ends_at?->gt(now()) ? now() : $subscription->ends_at,
            'note' => $reason,
            'decided_by' => $by?->id,
            'decided_at' => $by ? now() : null,
        ], fn ($v) => $v !== null));
    }
}
