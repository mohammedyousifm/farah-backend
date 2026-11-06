@extends('payments.layout')

@php($paid = $payment->status->value === 'paid')

@section('title', $paid ? 'تم الدفع بنجاح' : 'لم يتم الدفع')

@section('content')
    <div class="center">
        <span class="sandbox">وضع تجريبي</span>
        <div class="icon {{ $paid ? 'ok' : 'bad' }}">{{ $paid ? '✓' : '✕' }}</div>

        @if ($paid)
            <h1>تم الدفع بنجاح</h1>
            <p class="muted">{{ isset($payment->payload['subscription_id']) ? 'تم تفعيل باقتك. ارجع إلى لوحة التحكم لرؤية مزاياها وتاريخ انتهائها.' : 'تم تأكيد حجزك. يمكنك العودة إلى التطبيق لعرض رمز الحجز.' }}</p>
        @else
            <h1>لم تكتمل عملية الدفع</h1>
            <p class="muted">{{ $error ?? 'تم رفض العملية. يمكنك المحاولة مرة أخرى من التطبيق.' }}</p>
        @endif

        <div class="amount">{{ number_format($payment->amount, 2) }} <small>ر.س</small></div>

        @foreach ($payment->orders as $order)
            <div class="muted">{{ $order->number }} &middot; {{ $order->status->getLabel() }}</div>
        @endforeach

        @if ($payment->payload['return_url'] ?? null)
            <a class="btn btn-app" href="{{ $payment->payload['return_url'] }}">العودة إلى لوحة التحكم</a>
        @else
            <a class="btn btn-app" href="farah://payments/{{ $payment->reference }}?status={{ $payment->status->value }}">العودة إلى التطبيق</a>
        @endif
        <p><code>{{ $payment->reference }}</code></p>
    </div>
@endsection
