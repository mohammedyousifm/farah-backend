<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Fmt;
use App\Services\KpiService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KpiStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        [$from, $to] = KpiService::range($this->pageFilters['from'] ?? null, $this->pageFilters['to'] ?? null);
        $kpi = app(KpiService::class)->summary($from, $to);
        $series = app(KpiService::class)->salesSeries($from, $to);

        return [
            Stat::make(__('admin.kpi.gmv'), Fmt::sar($kpi['gmv']))
                ->description(__('admin.kpi.gmv_hint'))->icon('heroicon-o-banknotes')->color('primary')
                ->chart(array_slice($series['gmv'], -14)),
            Stat::make(__('admin.kpi.commission'), Fmt::sar($kpi['commission']))
                ->description(__('admin.kpi.commission_hint'))->icon('heroicon-o-receipt-percent')->color('success')
                ->chart(array_slice($series['commission'], -14)),
            Stat::make(__('admin.kpi.orders'), number_format($kpi['orders']))
                ->description(__('admin.kpi.aov').': '.Fmt::sar($kpi['aov']))->icon('heroicon-o-shopping-bag')->color('info')
                ->chart(array_slice($series['orders'], -14)),
            Stat::make(__('admin.kpi.refund_rate'), $kpi['refund_rate'].'%')
                ->description(__('admin.kpi.refunded').': '.Fmt::sar($kpi['refunded']))->icon('heroicon-o-arrow-uturn-left')
                ->color($kpi['refund_rate'] > 5 ? 'danger' : 'gray'),
            Stat::make(__('admin.kpi.new_customers'), number_format($kpi['new_customers']))->icon('heroicon-o-user-plus'),
            Stat::make(__('admin.kpi.active_providers'), number_format($kpi['active_providers']))->icon('heroicon-o-building-storefront'),
            Stat::make(__('admin.kpi.live_venues'), number_format($kpi['live_venues']))->icon('heroicon-o-map-pin'),
            Stat::make(__('admin.kpi.bookings_today'), number_format($kpi['bookings_today']))->description(__('admin.kpi.bookings_today_hint'))->icon('heroicon-o-calendar-days'),
        ];
    }
}
