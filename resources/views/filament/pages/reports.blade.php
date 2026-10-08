<x-filament-panels::page>
    @php
        $report = $this->getReport();
        $meta = $this->meta();
        $money = $report['money'] ?? [];
    @endphp

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ $this->reportTitle() }}</x-slot>
        <x-slot name="description">
            {{ __('admin.reports.period') }}: {{ $meta['from'] }} — {{ $meta['to'] }}
            @foreach ($meta['filters'] as $label => $value)
                · {{ $label }}: {{ $value }}
            @endforeach
            · {{ trans_choice('admin.reports.rows_count', count($report['rows']), ['count' => number_format(count($report['rows']))]) }}
        </x-slot>

        <div class="farah-report" wire:loading.class="farah-report-loading">
            @if (empty($report['rows']))
                <div class="farah-report-empty">{{ __('admin.reports.no_data') }}</div>
            @else
                <div class="farah-report-scroll">
                    <table class="farah-report-table">
                        <thead>
                            <tr>
                                @foreach ($report['columns'] as $key => $label)
                                    <th @class(['num' => in_array($key, $money, true)])>{{ $label }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['rows'] as $row)
                                <tr>
                                    @foreach ($report['columns'] as $key => $label)
                                        <td @class(['num' => is_numeric($row[$key] ?? null)])>{{ $this->formatCell($key, $row[$key] ?? null) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                        @if ($report['totals'])
                            <tfoot>
                                <tr>
                                    @foreach ($report['columns'] as $key => $label)
                                        <td @class(['num' => is_numeric($report['totals'][$key] ?? null)])>{{ $this->formatCell($key, $report['totals'][$key] ?? null) }}</td>
                                    @endforeach
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            @endif
        </div>
    </x-filament::section>

    <style>
        .farah-report-loading { opacity: .5; transition: opacity .15s; }
        .farah-report-scroll { overflow-x: auto; }
        .farah-report-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .farah-report-table th {
            text-align: start; font-weight: 600; padding: .6rem .75rem; white-space: nowrap;
            background: rgba(178, 31, 209, .07); border-bottom: 1px solid rgba(127, 127, 127, .25);
        }
        .farah-report-table td { padding: .5rem .75rem; border-bottom: 1px solid rgba(127, 127, 127, .12); white-space: nowrap; }
        .farah-report-table tbody tr:hover td { background: rgba(127, 127, 127, .06); }
        .farah-report-table .num { text-align: end; font-variant-numeric: tabular-nums; }
        .farah-report-table tfoot td { font-weight: 700; background: rgba(178, 31, 209, .1); border-top: 2px solid rgba(178, 31, 209, .45); }
        .farah-report-empty { padding: 2.5rem 1rem; text-align: center; opacity: .6; }
    </style>
</x-filament-panels::page>
