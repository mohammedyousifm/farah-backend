<?php

namespace App\Filament\Provider\Widgets;

use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Fmt;
use App\Services\LedgerService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Wallet balances (LedgerService::balances) on top of the finance statement. */
class BalancesWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 3;
    }

    public static function canView(): bool
    {
        return ProviderAccess::canView('finance', 'finance.view');
    }

    protected function getStats(): array
    {
        $provider = ProviderAccess::provider();
        $b = app(LedgerService::class)->balances($provider);

        return [
            Stat::make(__('provider.balances.available'), Fmt::sar($b['available']))
                ->description(__('provider.balances.available_hint'))->icon('heroicon-o-wallet')->color('success'),
            Stat::make(__('provider.balances.pending'), Fmt::sar($b['pending']))
                ->description(__('provider.balances.pending_hint'))->icon('heroicon-o-clock')->color('warning'),
            Stat::make(__('provider.balances.in_settlement'), Fmt::sar($b['in_settlement']))
                ->description(__('provider.balances.in_settlement_hint'))->icon('heroicon-o-arrow-path'),
            Stat::make(__('provider.balances.paid_out'), Fmt::sar($b['paid_out']))->icon('heroicon-o-building-library')->color('info'),
            Stat::make(__('provider.balances.lifetime_sales'), Fmt::sar($b['lifetime_sales']))->icon('heroicon-o-banknotes'),
            Stat::make(__('provider.balances.lifetime_commission'), Fmt::sar($b['lifetime_commission']))->icon('heroicon-o-receipt-percent'),
        ];
    }
}
