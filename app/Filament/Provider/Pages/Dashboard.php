<?php

namespace App\Filament\Provider\Pages;

use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Provider\Widgets\LatestOrdersWidget;
use App\Filament\Provider\Widgets\OnboardingWidget;
use App\Filament\Provider\Widgets\ProviderStatsWidget;
use App\Filament\Provider\Widgets\UpcomingBookingsWidget;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Provider home. Before approval it shows the onboarding checklist instead of the stats;
 * after approval the same checklist guides the provider until the first venue is live.
 */
class Dashboard extends BaseDashboard
{
    protected static ?int $navigationSort = -10;

    public static function getNavigationLabel(): string
    {
        return __('provider.dashboard.title');
    }

    public function getTitle(): string
    {
        return __('provider.dashboard.title');
    }

    public static function canAccess(): bool
    {
        return ProviderAccess::canView('dashboard');
    }

    public function getWidgets(): array
    {
        if (ProviderAccess::onboarding()) {
            return [OnboardingWidget::class];
        }

        return [
            ...(OnboardingWidget::needsVenueSetup($provider = ProviderAccess::provider()) || OnboardingWidget::hasOpenRequests($provider)
                ? [OnboardingWidget::class] : []),
            ProviderStatsWidget::class, UpcomingBookingsWidget::class, LatestOrdersWidget::class,
        ];
    }

    /** What counter staff do all day (check-in, today's schedule), one click from the home page. */
    protected function getHeaderActions(): array
    {
        if (ProviderAccess::onboarding()) {
            return [];
        }

        return [
            Action::make('check_in')->label(__('provider.checkin.title'))->icon('heroicon-o-qr-code')
                ->url(CheckIn::getUrl())->visible(fn () => CheckIn::canAccess()),
            Action::make('schedule')->label(__('provider.dashboard.today_schedule'))->icon('heroicon-o-calendar-days')->color('gray')
                ->url(Schedule::getUrl())->visible(fn () => Schedule::canAccess()),
        ];
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 2];
    }
}
