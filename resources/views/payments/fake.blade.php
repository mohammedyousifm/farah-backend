@extends('payments.layout')

@section('title', 'إتمام الدفع')

@section('content')
    <span class="sandbox">وضع تجريبي</span>
    <h1>إتمام الدفع</h1>

    <div class="muted">المبلغ المطلوب</div>
    <div class="amount">{{ number_format($payment->amount, 2) }} <small>{{ $payment->currency === 'SAR' ? 'ر.س' : $payment->currency }}</small></div>

    <ul class="lines">
        @foreach ($payment->orders as $order)
            <li>
                <span>{{ $order->venue?->name_ar }}<br>
                    @foreach ($order->bookings as $booking)
                        <span class="muted">{{ $booking->name }} &middot; {{ $booking->starts_at->timezone(config('farah.timezone'))->format('Y-m-d H:i') }}</span><br>
                    @endforeach
                    <span class="muted">{{ $order->number }}</span></span>
                <strong>{{ number_format($order->total, 2) }}</strong>
            </li>
        @endforeach
    </ul>

    @if ($payment->status->value === 'initiated')
        <form method="post" action="{{ route('payments.fake.complete', $payment->reference) }}">
            <button class="btn btn-pay" type="submit" name="action" value="pay">ادفع الآن (مدى)</button>
            <button class="btn btn-fail" type="submit" name="action" value="fail">محاكاة فشل الدفع</button>
        </form>
    @else
        <p class="center muted">تمت معالجة هذه العملية مسبقاً ({{ $payment->status->getLabel() }}).</p>
        <a class="btn btn-app" href="farah://payments/{{ $payment->reference }}">العودة إلى التطبيق</a>
    @endif

    <p class="center"><code>{{ $payment->reference }}</code></p>
@endsection
