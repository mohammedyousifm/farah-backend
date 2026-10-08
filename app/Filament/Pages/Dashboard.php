<?php

namespace App\Filament\Pages;

use App\Filament\NavGroup;
use App\Filament\Widgets\ExpiringDocumentsWidget;
use App\Filament\Widgets\KpiStatsWidget;
use App\Filament\Widgets\LatestOrdersWidget;
use App\Filament\Widgets\OrdersBySectorChart;
use App\Filament\Widgets\SalesChart;
use App\Filament\Widgets\WorkQueueWidget;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Home;

    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard.title');
    }

    public function getTitle(): string
    {
        return __('admin.dashboard.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can('dashboard.view') || $user?->can('dashboard.manage'));
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('from')->label(__('admin.fields.from'))->default(now()->subDays(29)->toDateString()),
            DatePicker::make('to')->label(__('admin.fields.to'))->default(now()->toDateString()),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            KpiStatsWidget::class,
            WorkQueueWidget::class,
            SalesChart::class,
            OrdersBySectorChart::class,
            LatestOrdersWidget::class,
            ExpiringDocumentsWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 2];
    }
}
