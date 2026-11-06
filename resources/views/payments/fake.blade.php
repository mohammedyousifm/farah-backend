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
        @if ($subscription = \App\Models\ProviderSubscription::with('plan', 'provider')->find($payment->payload['subscription_id'] ?? 0))
            <li>
                <span>اشتراك باقة {{ $subscription->plan->name_ar }}<br>
                    <span class="muted">{{ $subscription->provider->name_ar }} &middot; {{ $subscription->duration_days }} يوماً</span></span>
                <strong>{{ number_format($subscription->price, 2) }}</strong>
            </li>
        @endif
    </ul>

    @if ($payment->status->value === 'initiated')
        <form method="post" action="{{ $action }}">
            <button class="btn btn-pay" type="submit" name="action" value="pay">ادفع الآن (مدى)</button>
            <button class="btn btn-fail" type="submit" name="action" value="fail">محاكاة فشل الدفع</button>
        </form>
    @else
        <p class="center muted">تمت معالجة هذه العملية مسبقاً ({{ $payment->status->getLabel() }}).</p>
        @if ($payment->payload['return_url'] ?? null)
            <a class="btn btn-app" href="{{ $payment->payload['return_url'] }}">العودة إلى لوحة التحكم</a>
        @else
            <a class="btn btn-app" href="farah://payments/{{ $payment->reference }}">العودة إلى التطبيق</a>
        @endif
    @endif

    <p class="center"><code>{{ $payment->reference }}</code></p>
@endsection
