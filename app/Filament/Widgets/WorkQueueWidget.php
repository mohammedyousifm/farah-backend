<?php

namespace App\Filament\Widgets;

use App\Services\KpiService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Items waiting for an admin decision; each card links to its work-queue. */
class WorkQueueWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getHeading(): ?string
    {
        return __('admin.kpi.work_queue');
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $q = app(KpiService::class)->queue();

        $cards = [
            'providers' => ['admin/providers?tableFilters[status][value]=pending', 'heroicon-o-building-storefront'],
            'documents' => ['admin/provider-documents', 'heroicon-o-document-check'],
            'venues' => ['admin/venues?tableFilters[status][value]=pending_review', 'heroicon-o-map-pin'],
            'activities' => ['admin/venues?tableFilters[pending_activities][isActive]=1', 'heroicon-o-puzzle-piece'],
            'sector_requests' => ['admin/sector-requests', 'heroicon-o-squares-plus'],
            'refunds' => ['admin/refunds', 'heroicon-o-arrow-uturn-left'],
            'disputes' => ['admin/disputes', 'heroicon-o-scale'],
            'complaints' => ['admin/complaints', 'heroicon-o-chat-bubble-left-right'],
            'settlements' => ['admin/settlements', 'heroicon-o-building-library'],
        ];

        return collect($cards)->map(fn ($c, $key) => Stat::make(__("admin.queue.{$key}"), (string) $q[$key])
            ->icon($c[1])
            ->color($q[$key] > 0 ? 'warning' : 'gray')
            ->url(url($c[0])))->values()->all();
    }
}
