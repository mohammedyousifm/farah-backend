@php
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
    $money = fn ($amount) => number_format((float) $amount, 2).' '.__('invoice.sar');
    $when = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format('Y-m-d H:i') : '';
    $taxed = $invoice['is_tax_invoice'];
@endphp
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}" lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans; font-size: 9.5pt; color: #1f2937; }
        .brand { border-bottom: 2px solid #B21FD1; padding-bottom: 8px; margin-bottom: 14px; }
        .brand-name { font-size: 17pt; font-weight: bold; color: #B21FD1; }
        .title { font-size: 14pt; font-weight: bold; }
        .muted { color: #6b7280; font-size: 8.5pt; }
        .label { color: #6b7280; font-size: 8.5pt; }
        .box { border: 1px solid #e5e7eb; padding: 8px 10px; }
        .box-title { font-weight: bold; color: #4c1d95; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        table.lines { margin-top: 14px; }
        table.lines th { background: #f3e8ff; color: #4c1d95; font-weight: bold; padding: 6px 5px; border: 1px solid #ddd6fe; text-align: {{ $start }}; }
        table.lines td { padding: 6px 5px; border: 1px solid #e5e7eb; text-align: {{ $start }}; }
        .num { text-align: {{ $end }} !important; }
        table.totals td { padding: 5px; border-bottom: 1px solid #e5e7eb; }
        table.totals tr.grand td { background: #ede9fe; font-weight: bold; font-size: 11pt; border-bottom: none; }
        .note { margin-top: 14px; padding: 8px 10px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 8.5pt; }
    </style>
</head>
<body>
    <table class="brand">
        <tr>
            <td style="text-align: {{ $start }}">
                <div class="brand-name">فرة | Farah</div>
                <div class="title">{{ $taxed ? __('invoice.tax_title') : __('invoice.plain_title') }}</div>
            </td>
            <td style="text-align: {{ $end }}">
                <div><span class="label">{{ __('invoice.number') }}:</span> <b>{{ $invoice['number'] }}</b></div>
                <div><span class="label">{{ __('invoice.issued_at') }}:</span> {{ $when($invoice['issued_at']) }}</div>
                <div><span class="label">{{ __('invoice.order_number') }}:</span> {{ $invoice['order_number'] }}</div>
            </td>
        </tr>
    </table>

    <table>
        <tr>
            <td width="49%" class="box" style="vertical-align: top">
                <div class="box-title">{{ __('invoice.seller') }}</div>
                <div><b>{{ $invoice['seller']['name'] }}</b></div>
                @if ($invoice['venue_name'] && $invoice['venue_name'] !== $invoice['seller']['name'])
                    <div>{{ $invoice['venue_name'] }}</div>
                @endif
                @if ($invoice['seller']['vat_number'])
                    <div><span class="label">{{ __('invoice.vat_number') }}:</span> {{ $invoice['seller']['vat_number'] }}</div>
                @endif
                @if ($invoice['seller']['cr_number'])
                    <div><span class="label">{{ __('invoice.cr_number') }}:</span> {{ $invoice['seller']['cr_number'] }}</div>
                @endif
                @if ($invoice['seller']['address'])
                    <div class="muted">{{ $invoice['seller']['address'] }}</div>
                @endif
            </td>
            <td width="2%"></td>
            <td width="49%" class="box" style="vertical-align: top">
                <div class="box-title">{{ __('invoice.buyer') }}</div>
                <div><b>{{ $invoice['buyer']['name'] ?: '—' }}</b></div>
                @if ($invoice['buyer']['phone'])
                    <div><bdo dir="ltr">{{ $invoice['buyer']['phone'] }}</bdo></div>
                @endif
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('invoice.description') }}</th>
                <th>{{ __('invoice.time') }}</th>
                <th class="num">{{ __('invoice.quantity') }}</th>
                <th class="num">{{ __('invoice.unit_price') }}</th>
                <th class="num">{{ $taxed ? __('invoice.line_total_with_vat') : __('invoice.line_total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice['lines'] as $line)
                <tr>
                    <td>{{ $line['description'] }}</td>
                    <td><bdo dir="ltr">{{ $when($line['starts_at']) }} – {{ $line['ends_at'] ? \Illuminate\Support\Carbon::parse($line['ends_at'])->timezone(config('app.timezone'))->format('H:i') : '' }}</bdo></td>
                    <td class="num">{{ $line['quantity'] }}</td>
                    <td class="num">{{ $money($line['unit_price']) }}</td>
                    <td class="num">{{ $money($line['total']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 14px">
        <tr>
            <td width="45%" style="vertical-align: top; text-align: {{ $start }}">
                @if ($qr)
                    <img src="{{ $qr }}" width="120" height="120" alt="">
                    <div class="muted">{{ __('invoice.qr_hint') }}</div>
                @endif
            </td>
            <td width="55%" style="vertical-align: top">
                <table class="totals">
                    @if ($invoice['discount'] > 0)
                        <tr><td>{{ __('invoice.discount') }}</td><td class="num">− {{ $money($invoice['discount']) }}</td></tr>
                    @endif
                    @if ($taxed)
                        <tr><td>{{ __('invoice.net') }}</td><td class="num">{{ $money($invoice['net']) }}</td></tr>
                        <tr><td>{{ __('invoice.vat', ['rate' => $invoice['vat_rate']]) }}</td><td class="num">{{ $money($invoice['vat_amount']) }}</td></tr>
                    @endif
                    <tr class="grand"><td>{{ $taxed ? __('invoice.total_with_vat') : __('invoice.total') }}</td><td class="num">{{ $money($invoice['total']) }}</td></tr>
                    @if ($invoice['refunded_amount'] > 0)
                        <tr><td>{{ __('invoice.refunded') }}</td><td class="num">− {{ $money($invoice['refunded_amount']) }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @unless ($taxed)
        <div class="note">{{ __('invoice.not_registered') }}</div>
    @endunless
</body>
</html>
