@php
    $tz = config('farah.timezone');
    // Without a running paid plan the provider is on the free plan.
    $currentPlanId = $current?->plan_id ?? $freePlan?->id;
@endphp
<x-filament-panels::page>
    {{-- The running plan --}}
    <x-filament::section icon="heroicon-o-check-badge" :heading="__('provider.plans.current')">
        @if ($current)
            <div class="farah-plan-current">
                <div>
                    <div class="farah-plan-name">{{ $current->plan->tr('name') }}</div>
                    <div class="farah-plan-dates">
                        {{ __('provider.plans.period', ['from' => $current->starts_at->timezone($tz)->format('Y-m-d'), 'to' => $current->ends_at->timezone($tz)->format('Y-m-d')]) }}
                    </div>
                </div>
                <x-filament::badge color="success" size="lg">{{ __('provider.plans.days_left', ['days' => $current->daysLeft()]) }}</x-filament::badge>
            </div>

            <ul class="farah-plan-features">
                @foreach (\App\Filament\Provider\Pages\MyPlan::featureRows($current) as $row)
                    <li @class(['is-off' => ! $row['running']])>
                        <span class="farah-plan-dot" aria-hidden="true"></span>
                        <span class="farah-plan-feature">{{ $row['label'] }} @if ($row['detail'])<small>({{ $row['detail'] }})</small>@endif</span>
                        <span class="farah-plan-until">
                            {{ $row['running'] ? __('provider.plans.until', ['date' => $row['ends']]) : __('provider.plans.ended', ['date' => $row['ends']]) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @elseif ($freePlan)
            <div class="farah-plan-current">
                <div>
                    <div class="farah-plan-name">{{ $freePlan->tr('name') }}</div>
                    <div class="farah-plan-dates">{{ __('provider.plans.free_hint') }}</div>
                </div>
                <x-filament::badge color="gray" size="lg">{{ __('provider.plans.free') }}</x-filament::badge>
            </div>
            <ul class="farah-plan-features">
                @foreach ($freePlan->items as $item)
                    <li>
                        <span class="farah-plan-dot" aria-hidden="true"></span>
                        <span class="farah-plan-feature">{{ $item->feature->getLabel() }} @if ($item->value)<small>({{ __('provider.plans.up_to', ['count' => $item->value]) }})</small>@endif</span>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="farah-plan-muted">{{ __('provider.plans.no_plan') }}</p>
        @endif
        <p class="farah-plan-muted">{{ __('provider.plans.activities_unlimited') }}</p>
    </x-filament::section>

    {{-- A request waiting for the platform --}}
    @if ($pending)
        <x-filament::section icon="heroicon-o-clock" :heading="__('provider.plans.pending')">
            <div class="farah-plan-current">
                <p>
                    {{ __('provider.plans.pending_body', ['plan' => $pending->plan->tr('name'), 'price' => \App\Filament\Support\Fmt::sar($pending->price)]) }}
                    @if ($onboarding) {{ __('provider.plans.pending_after_approval') }} @endif
                </p>
                {{ $this->cancelRequestAction }}
            </div>
        </x-filament::section>
    @endif

    {{-- Plans to choose from --}}
    <x-filament::section icon="heroicon-o-rectangle-stack" :heading="__('provider.plans.available')" :description="__('provider.plans.available_hint')">
        @if ($plans->isEmpty())
            <p class="farah-plan-muted">{{ __('provider.plans.none_available') }}</p>
        @else
            <div class="farah-plan-grid">
                @foreach ($plans as $plan)
                    <div @class(['farah-plan-card', 'is-current' => $plan->id === $currentPlanId])>
                        <div class="farah-plan-name">{{ $plan->tr('name') }}</div>
                        <div class="farah-plan-price">
                            @if ($plan->is_free)
                                {{ __('provider.plans.free') }}
                            @else
                                {{ \App\Filament\Support\Fmt::sar($plan->price) }} <small>/ {{ __('provider.plans.for_days', ['days' => $plan->duration_days]) }}</small>
                            @endif
                        </div>
                        @if ($plan->tr('description'))
                            <p class="farah-plan-muted">{{ $plan->tr('description') }}</p>
                        @endif
                        <ul class="farah-plan-features">
                            @foreach ($plan->items as $item)
                                <li>
                                    <span class="farah-plan-dot" aria-hidden="true"></span>
                                    <span class="farah-plan-feature">
                                        {{ $item->feature->getLabel() }}
                                        @if ($item->value)<small>({{ __('provider.plans.up_to', ['count' => $item->value]) }})</small>@endif
                                    </span>
                                    @if (! $plan->is_free && $item->days && $item->days < $plan->duration_days)
                                        <span class="farah-plan-until">{{ __('provider.plans.for_days', ['days' => $item->days]) }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <div class="farah-plan-cta">
                            @if ($plan->id === $currentPlanId)
                                <x-filament::badge color="success">{{ __('provider.plans.your_plan') }}</x-filament::badge>
                            @elseif ($plan->is_free)
                                <span class="farah-plan-muted">{{ __('provider.plans.free_returns') }}</span>
                            @elseif ($canRequest && ! $pending)
                                {{ ($this->requestAction)(['plan' => $plan->id]) }}
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    @if ($history->isNotEmpty())
        <x-filament::section icon="heroicon-o-clock" :heading="__('provider.plans.history')" collapsible collapsed>
            <table class="farah-plan-table">
                <thead>
                    <tr>
                        <th>{{ __('provider.plans.plan') }}</th>
                        <th>{{ __('provider.fields.status') }}</th>
                        <th>{{ __('provider.plans.price') }}</th>
                        <th>{{ __('provider.plans.from') }}</th>
                        <th>{{ __('provider.plans.to') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($history as $row)
                        <tr>
                            <td>{{ $row->plan->tr('name') }}</td>
                            <td>
                                <x-filament::badge :color="$row->isExpired() ? 'gray' : $row->status->getColor()">
                                    {{ $row->isExpired() ? __('enums.subscription_status.expired') : $row->status->getLabel() }}
                                </x-filament::badge>
                            </td>
                            <td>{{ \App\Filament\Support\Fmt::sar($row->price) }}</td>
                            <td>{{ $row->starts_at?->timezone($tz)->format('Y-m-d') ?? '-' }}</td>
                            <td>{{ $row->ends_at?->timezone($tz)->format('Y-m-d') ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif

    <style>
        .farah-plan-current { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        .farah-plan-name { font-weight: 700; font-size: 1.1rem; }
        .farah-plan-dates, .farah-plan-muted { font-size: .875rem; opacity: .7; margin-top: .25rem; }
        .farah-plan-features { display: grid; gap: .45rem; margin: 1rem 0 .5rem; }
        .farah-plan-features li { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
        .farah-plan-features li.is-off { opacity: .5; }
        .farah-plan-features small { opacity: .7; }
        .farah-plan-dot { width: .5rem; height: .5rem; border-radius: 999px; background: var(--primary-600); flex: none; }
        .farah-plan-features li.is-off .farah-plan-dot { background: rgba(127,127,127,.6); }
        .farah-plan-feature { flex: 1; }
        .farah-plan-until { font-size: .8rem; opacity: .7; }
        .farah-plan-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr)); }
        .farah-plan-card { border: 1px solid rgba(127,127,127,.25); border-radius: .9rem; padding: 1rem; display: flex; flex-direction: column; }
        .farah-plan-card.is-current { border-color: var(--primary-600); box-shadow: 0 0 0 1px var(--primary-600); }
        .farah-plan-price { font-size: 1.35rem; font-weight: 700; color: var(--primary-600); margin-top: .35rem; }
        .farah-plan-price small { font-size: .8rem; font-weight: 400; opacity: .7; color: inherit; }
        .farah-plan-cta { margin-top: auto; padding-top: .75rem; }
        .farah-plan-table { width: 100%; font-size: .875rem; }
        .farah-plan-table th { text-align: start; font-weight: 600; opacity: .7; padding: .4rem .5rem; }
        .farah-plan-table td { padding: .5rem; border-top: 1px solid rgba(127,127,127,.15); }
    </style>
</x-filament-panels::page>
