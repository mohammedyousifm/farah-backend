<?php

namespace App\Filament\Widgets;

use App\Services\KpiService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class SalesChart extends ChartWidget
{
    protected static bool $isLazy = false;

    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 3;

    protected ?string $maxHeight = '280px';

    public function getHeading(): ?string
    {
        return __('admin.kpi.sales_chart');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        $font = ['family' => 'Almarai', 'size' => 13];

        return [
            'plugins' => ['legend' => ['labels' => ['font' => $font, 'usePointStyle' => true, 'padding' => 16]]],
            'scales' => ['x' => ['ticks' => ['font' => $font, 'maxTicksLimit' => 10]], 'y' => ['ticks' => ['font' => $font]]],
        ];
    }

    protected function getData(): array
    {
        [$from, $to] = KpiService::range($this->pageFilters['from'] ?? null, $this->pageFilters['to'] ?? null);
        $s = app(KpiService::class)->salesSeries($from, $to);

        return [
            'labels' => $s['labels'],
            'datasets' => [
                ['label' => __('admin.kpi.gmv'), 'data' => $s['gmv'], 'borderColor' => '#B21FD1', 'backgroundColor' => 'rgba(178,31,209,.12)', 'fill' => true, 'tension' => .35],
                ['label' => __('admin.kpi.commission'), 'data' => $s['commission'], 'borderColor' => '#F9B233', 'backgroundColor' => 'transparent', 'tension' => .35],
            ],
        ];
    }
}
