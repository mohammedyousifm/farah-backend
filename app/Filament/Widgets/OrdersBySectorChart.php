<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Brand;
use App\Services\KpiService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class OrdersBySectorChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 4;

    protected ?string $maxHeight = '280px';

    public function getHeading(): ?string
    {
        return __('admin.kpi.by_sector');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        [$from, $to] = KpiService::range($this->pageFilters['from'] ?? null, $this->pageFilters['to'] ?? null);
        $s = app(KpiService::class)->ordersBySector($from, $to);
        $palette = Brand::CHART;

        return [
            'labels' => $s['labels'],
            'datasets' => [[
                'label' => __('admin.kpi.gmv'),
                'data' => $s['gmv'],
                'backgroundColor' => array_slice(array_merge($palette, $palette), 0, max(1, count($s['gmv']))),
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
        ];
    }
}
