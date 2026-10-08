@php
    $provider = \App\Filament\Provider\Support\ProviderAccess::provider();
    $status = $provider?->status?->value;
@endphp

@if ($provider && $status !== 'approved')
    <div @class([
        'farah-status-banner',
        'is-pending' => $status === 'pending',
        'is-rejected' => $status === 'rejected',
        'is-suspended' => $status === 'suspended',
    ]) role="alert">
        <div class="farah-status-banner-title">{{ __("provider.banner.{$status}.title") }}</div>
        <div class="farah-status-banner-body">{{ __("provider.banner.{$status}.body") }}</div>
        @if (in_array($status, ['rejected', 'suspended'], true) && filled($provider->rejection_reason))
            <div class="farah-status-banner-reason"><strong>{{ __('provider.fields.reason') }}:</strong> {{ $provider->rejection_reason }}</div>
        @endif
    </div>

    <style>
        .farah-status-banner { border-radius: .9rem; padding: .9rem 1.1rem; margin-block: 1.25rem 0; border: 1px solid; }
        .farah-status-banner-title { font-weight: 700; }
        .farah-status-banner-body { font-size: .9rem; opacity: .9; margin-top: .15rem; }
        .farah-status-banner-reason { font-size: .9rem; margin-top: .4rem; }
        .farah-status-banner.is-pending { background: rgba(245,158,11,.1); border-color: rgba(245,158,11,.4); color: #92400e; }
        .farah-status-banner.is-rejected, .farah-status-banner.is-suspended { background: rgba(225,29,72,.08); border-color: rgba(225,29,72,.35); color: #9f1239; }
        .dark .farah-status-banner.is-pending { color: #fcd34d; }
        .dark .farah-status-banner.is-rejected, .dark .farah-status-banner.is-suspended { color: #fda4af; }
    </style>
@endif
