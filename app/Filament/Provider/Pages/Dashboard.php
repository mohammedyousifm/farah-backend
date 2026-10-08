<?php

namespace App\Filament\Provider\Pages;

use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Provider\Widgets\LatestOrdersWidget;
use App\Filament\Provider\Widgets\OnboardingWidget;
use App\Filament\Provider\Widgets\ProviderStatsWidget;
use App\Filament\Provider\Widgets\UpcomingBookingsWidget;
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
            ...(OnboardingWidget::needsVenueSetup(ProviderAccess::provider()) ? [OnboardingWidget::class] : []),
            ProviderStatsWidget::class, UpcomingBookingsWidget::class, LatestOrdersWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 2];
    }
}
