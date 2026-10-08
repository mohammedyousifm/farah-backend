@php
    $money = $report['money'] ?? [];
    $fmt = fn ($key, $value) => in_array($key, $money, true) && is_numeric($value) ? number_format((float) $value, 2) : $value;
@endphp
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}" lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans; font-size: 9pt; color: #1f2937; }
        .brand { border-bottom: 2px solid #B21FD1; padding-bottom: 6px; margin-bottom: 10px; }
        .brand-name { font-size: 16pt; font-weight: bold; color: #B21FD1; }
        .title { font-size: 13pt; font-weight: bold; margin: 4px 0; }
        .meta { color: #6b7280; font-size: 8.5pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { background: #f3e8ff; color: #4c1d95; font-weight: bold; padding: 5px 4px; border: 1px solid #ddd6fe; text-align: {{ $rtl ? 'right' : 'left' }}; }
        td { padding: 4px; border: 1px solid #e5e7eb; text-align: {{ $rtl ? 'right' : 'left' }}; }
        tr.alt td { background: #fafafa; }
        td.num, th.num { text-align: {{ $rtl ? 'left' : 'right' }}; }
        tr.total td { background: #ede9fe; font-weight: bold; }
        .empty { padding: 20px; text-align: center; color: #6b7280; }
    </style>
</head>
<body>
    <div class="brand">
        <span class="brand-name">فرة | Farah</span>
    </div>
    <div class="title">{{ str_replace('·', '-', $title) }}</div>
    <div class="meta">
        {{ __('admin.reports.period') }}: {{ $meta['from'] ?? '' }} — {{ $meta['to'] ?? '' }}
        @foreach (($meta['filters'] ?? []) as $label => $value)
            &nbsp;|&nbsp; {{ $label }}: {{ $value }}
        @endforeach
        &nbsp;|&nbsp; {{ __('admin.reports.currency_note') }}
        &nbsp;|&nbsp; {{ __('admin.reports.generated_at') }}: {{ now()->format('Y-m-d H:i') }}
    </div>

    @if (empty($report['rows']))
        <div class="empty">{{ __('admin.reports.no_data') }}</div>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($report['columns'] as $key => $label)
                        <th class="{{ in_array($key, $money, true) ? 'num' : '' }}">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($report['rows'] as $i => $row)
                    <tr class="{{ $i % 2 ? 'alt' : '' }}">
                        @foreach ($report['columns'] as $key => $label)
                            <td class="{{ is_numeric($row[$key] ?? null) ? 'num' : '' }}">{{ $fmt($key, $row[$key] ?? '') }}</td>
                        @endforeach
                    </tr>
                @endforeach
                @if ($report['totals'])
                    <tr class="total">
                        @foreach ($report['columns'] as $key => $label)
                            <td class="{{ is_numeric($report['totals'][$key] ?? null) ? 'num' : '' }}">{{ $fmt($key, $report['totals'][$key] ?? '') }}</td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
    @endif
</body>
</html>
