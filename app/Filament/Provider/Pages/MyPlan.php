<?php

namespace App\Filament\Provider\Pages;

use App\Enums\SubscriptionStatus;
use App\Exceptions\BusinessException;
use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Support\ProviderAccess;
use App\Models\Plan;
use App\Models\ProviderSubscription;
use App\Services\SubscriptionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * "My plan", open from sign-up on: the running plan (the free plan when no paid plan runs) with each feature
 * and its end date, the pending request, and the plans the provider can move to. Asking for a paid plan only
 * sends a request; the platform activates it once paid and the provider is approved.
 */
class MyPlan extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = ProviderNavGroup::Account;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'my-plan';

    protected string $view = 'filament.provider.my-plan';

    public static function canAccess(): bool
    {
        return ProviderAccess::canView('plans', 'finance.view');
    }

    public static function canRequest(): bool
    {
        return ProviderAccess::canManage('plans', 'profile.manage');
    }

    public static function getNavigationLabel(): string
    {
        return __('provider.plans.title');
    }

    public function getTitle(): string
    {
        return __('provider.plans.title');
    }

    protected function getViewData(): array
    {
        $provider = ProviderAccess::provider();
        $subscriptions = app(SubscriptionService::class);

        return [
            'current' => $subscriptions->current($provider),
            'freePlan' => Plan::free(),
            'pending' => $provider->subscriptions()->where('status', SubscriptionStatus::Pending)->with('plan')->latest('id')->first(),
            'plans' => Plan::active()->with('items')->orderByDesc('is_free')->orderBy('sort_order')->orderBy('price')->get(),
            'onboarding' => ProviderAccess::onboarding(),
            'history' => $provider->subscriptions()->with('plan')->latest('id')->limit(10)->get(),
            'canRequest' => static::canRequest(),
        ];
    }

    public function requestAction(): Action
    {
        return Action::make('request')
            ->label(__('provider.plans.choose'))
            ->icon('heroicon-o-arrow-up-circle')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => __('provider.plans.confirm_heading', ['plan' => Plan::find($arguments['plan'] ?? 0)?->tr('name')]))
            ->modalDescription(__('provider.plans.confirm_body'))
            ->visible(fn () => static::canRequest())
            ->action(function (array $arguments) {
                try {
                    app(SubscriptionService::class)->request(ProviderAccess::provider(), Plan::active()->findOrFail($arguments['plan'] ?? 0), auth()->user());
                    Notification::make()->success()->title(__('provider.plans.requested'))->send();
                } catch (BusinessException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public function cancelRequestAction(): Action
    {
        return Action::make('cancelRequest')
            ->label(__('provider.plans.cancel_request'))
            ->color('gray')->size('sm')
            ->requiresConfirmation()
            ->visible(fn () => static::canRequest())
            ->action(function () {
                $pending = ProviderAccess::provider()->subscriptions()->where('status', SubscriptionStatus::Pending)->first();
                if ($pending) {
                    app(SubscriptionService::class)->cancel($pending, auth()->user());
                }
                Notification::make()->success()->title(__('provider.plans.request_cancelled'))->send();
            });
    }

    /** @return Collection<int, array{label: string, detail: ?string, ends: string, running: bool}> */
    public static function featureRows(ProviderSubscription $subscription): Collection
    {
        $tz = config('farah.timezone');

        return $subscription->features->map(fn ($f) => [
            'label' => $f->feature->getLabel(),
            'detail' => $f->value ? __('provider.plans.up_to', ['count' => $f->value]) : null,
            'ends' => $f->ends_at->timezone($tz)->format('Y-m-d'),
            'running' => $f->isRunning(),
        ]);
    }
}
