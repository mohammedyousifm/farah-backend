<x-filament-panels::page>
    @php
        $venues = $this->getVenueOptions();
        $activities = $this->getActivityOptions();
        $grid = $this->getGrid();
        $canBook = \App\Filament\Provider\Pages\Schedule::canBook();
        $canBlock = \App\Filament\Provider\Pages\Schedule::canBlock();
        $canAct = $canBook || $canBlock;
    @endphp

    <div class="farah-schedule">
        @if ($venues === [])
            <x-filament::section>
                <div class="farah-schedule-empty">
                    <p class="farah-schedule-empty-title">{{ __('provider.schedule.no_venues') }}</p>
                    <p>{{ __('provider.schedule.no_venues_hint') }}</p>
                    <x-filament::button tag="a" :href="\App\Filament\Provider\Resources\Venues\VenueResource::getUrl()" icon="heroicon-o-building-storefront">
                        {{ __('provider.resources.venues.plural') }}
                    </x-filament::button>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="farah-schedule-filters">
                    <div>
                        <label class="farah-schedule-label" for="farah-schedule-venue">{{ __('provider.fields.venue') }}</label>
                        <select id="farah-schedule-venue" wire:model.live="venueId" class="farah-schedule-select">
                            @foreach ($venues as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="farah-schedule-label" for="farah-schedule-activity">{{ __('provider.fields.activity') }}</label>
                        <select id="farah-schedule-activity" wire:model.live="activityId" class="farah-schedule-select" @disabled($activities === [])>
                            @foreach ($activities as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="farah-schedule-label" for="farah-schedule-date">{{ __('provider.fields.date') }}</label>
                        <div class="farah-schedule-date">
                            <button type="button" wire:click="shiftDay(-1)" class="farah-schedule-nav" title="{{ __('provider.schedule.previous_day') }}" aria-label="{{ __('provider.schedule.previous_day') }}">‹</button>
                            <input id="farah-schedule-date" type="date" wire:model.live="date" class="farah-schedule-select">
                            <button type="button" wire:click="shiftDay(1)" class="farah-schedule-nav" title="{{ __('provider.schedule.next_day') }}" aria-label="{{ __('provider.schedule.next_day') }}">›</button>
                            <button type="button" wire:click="today" class="farah-schedule-today">{{ __('provider.schedule.today') }}</button>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            @if (! $grid)
                <x-filament::section>
                    <div class="farah-schedule-empty">
                        <p class="farah-schedule-empty-title">{{ __('provider.schedule.no_activities') }}</p>
                        <p>{{ __('provider.schedule.no_activities_hint') }}</p>
                        <x-filament::button tag="a" :href="\App\Filament\Provider\Resources\Venues\VenueResource::getUrl('view', ['record' => $this->venueId])" icon="heroicon-o-plus">
                            {{ __('provider.venues.add_activity') }}
                        </x-filament::button>
                    </div>
                </x-filament::section>
            @elseif ($grid['resources'] === [])
                <x-filament::section>
                    <div class="farah-schedule-empty">
                        <p class="farah-schedule-empty-title">{{ __('provider.venues.no_resources') }}</p>
                        <p>{{ __('provider.venues.no_resources_hint') }}</p>
                        <x-filament::button tag="a" :href="\App\Filament\Provider\Resources\Venues\VenueResource::getUrl('view', ['record' => $this->venueId])" icon="heroicon-o-squares-plus">
                            {{ __('provider.venues.add_resources') }}
                        </x-filament::button>
                    </div>
                </x-filament::section>
            @elseif (! $grid['is_open'])
                <x-filament::section>
                    <div class="farah-schedule-empty">
                        <p class="farah-schedule-empty-title">{{ __('provider.schedule.closed_day') }}</p>
                        <p>{{ __('provider.schedule.closed_day_hint') }}</p>
                    </div>
                </x-filament::section>
            @else
                <x-filament::section>
                    <div class="farah-schedule-summary">
                        <span>{{ __('provider.schedule.working_hours') }}: <strong dir="ltr">{{ $grid['opens_at'] }} - {{ $grid['closes_at'] }}</strong></span>
                        <span>{{ __('provider.fields.slot_minutes') }}: <strong>{{ __('provider.venues.minutes', ['count' => $grid['slot_minutes']]) }}</strong></span>
                        <span>{{ __('provider.fields.slot_price') }}: <strong>{{ \App\Filament\Support\Fmt::sar($grid['slot_price']) }}</strong></span>
                        <span class="farah-schedule-legend">
                            <i class="is-available"></i>{{ __('provider.schedule.statuses.available') }} ({{ $grid['counts']['available'] }})
                            <i class="is-booked"></i>{{ __('provider.schedule.statuses.booked') }} ({{ $grid['counts']['booked'] }})
                            <i class="is-blocked"></i>{{ __('provider.schedule.statuses.blocked') }} ({{ $grid['counts']['blocked'] }})
                            <i class="is-past"></i>{{ __('provider.schedule.statuses.past') }}
                        </span>
                    </div>
                    @if ($canAct)
                        <p class="farah-schedule-hint">{{ __('provider.schedule.hint') }}</p>
                    @endif

                    <div class="farah-schedule-scroll" wire:loading.class="is-loading">
                        <table class="farah-schedule-table">
                            <thead>
                                <tr>
                                    <th class="farah-schedule-time">{{ __('provider.fields.time') }}</th>
                                    @foreach ($grid['resources'] as $resource)
                                        <th>{{ $resource['name'] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($grid['rows'] as $row)
                                    <tr>
                                        <th class="farah-schedule-time" dir="ltr">{{ $row['time'] }}<small>{{ $row['end'] }}</small></th>
                                        @foreach ($row['cells'] as $cell)
                                            <td wire:key="cell-{{ $cell['resource'] }}-{{ $cell['start'] }}">
                                                @if ($cell['status'] === 'available')
                                                    @if ($canAct)
                                                        <button type="button" class="farah-schedule-cell is-available"
                                                            wire:click="mountAction('slot', @js(['resource' => $cell['resource'], 'start' => $cell['start']]))">
                                                            {{ __('provider.schedule.statuses.available') }}
                                                        </button>
                                                    @else
                                                        <div class="farah-schedule-cell is-available">{{ __('provider.schedule.statuses.available') }}</div>
                                                    @endif
                                                @elseif ($cell['status'] === 'booked')
                                                    <{{ $cell['url'] ? 'a' : 'div' }} @if ($cell['url']) href="{{ $cell['url'] }}" @endif
                                                        @class(['farah-schedule-cell', 'is-booked', 'is-checked-in' => $cell['checked_in']])
                                                        title="{{ $cell['booking_status'] }}">
                                                        <span class="farah-schedule-customer">{{ $cell['customer'] }}</span>
                                                        <span class="farah-schedule-code" dir="ltr">{{ $cell['code'] }}</span>
                                                    </{{ $cell['url'] ? 'a' : 'div' }}>
                                                @elseif ($cell['status'] === 'blocked')
                                                    @if ($canBlock)
                                                        <button type="button" class="farah-schedule-cell is-blocked" title="{{ __('provider.schedule.unblock') }}"
                                                            wire:click="mountAction('unblock', @js(['slot' => $cell['slot']]))">
                                                            <span>{{ __('provider.schedule.statuses.blocked') }}</span>
                                                            @if ($cell['reason'])<span class="farah-schedule-code">{{ $cell['reason'] }}</span>@endif
                                                        </button>
                                                    @else
                                                        <div class="farah-schedule-cell is-blocked">
                                                            <span>{{ __('provider.schedule.statuses.blocked') }}</span>
                                                            @if ($cell['reason'])<span class="farah-schedule-code">{{ $cell['reason'] }}</span>@endif
                                                        </div>
                                                    @endif
                                                @else
                                                    <div class="farah-schedule-cell is-past">{{ __('provider.schedule.statuses.past') }}</div>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="farah-schedule-hint">{{ __('provider.venues.hours_timezone') }}</p>
                </x-filament::section>
            @endif
        @endif
    </div>

    <style>
        .farah-schedule { display: grid; gap: 1.25rem; }
        .farah-schedule-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem; align-items: end; }
        .farah-schedule-label { display: block; font-weight: 600; font-size: .85rem; margin-bottom: .3rem; }
        .farah-schedule-select { width: 100%; padding: .6rem .75rem; border-radius: .6rem; border: 1px solid rgba(127,127,127,.35); background: transparent; font-size: .95rem; }
        .dark .farah-schedule-select option { background: #18181b; }
        .dark .farah-schedule-select { color-scheme: dark; }
        .farah-schedule-date { display: flex; gap: .35rem; align-items: center; }
        .farah-schedule-nav, .farah-schedule-today { padding: .55rem .8rem; border-radius: .6rem; border: 1px solid rgba(127,127,127,.35); font-weight: 700; line-height: 1.2; white-space: nowrap; }
        [dir="rtl"] .farah-schedule-nav { transform: scaleX(-1); }
        .farah-schedule-nav:hover, .farah-schedule-today:hover { background: rgba(127,127,127,.12); }
        .farah-schedule-summary { display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; font-size: .9rem; align-items: center; }
        .farah-schedule-legend { display: inline-flex; flex-wrap: wrap; gap: .3rem .5rem; align-items: center; margin-inline-start: auto; font-size: .8rem; }
        .farah-schedule-legend i { width: .8rem; height: .8rem; border-radius: .25rem; display: inline-block; margin-inline-start: .5rem; }
        .farah-schedule-legend i.is-available { background: #10b981; }
        .farah-schedule-legend i.is-booked { background: #B21FD1; }
        .farah-schedule-legend i.is-blocked { background: #f59e0b; }
        .farah-schedule-legend i.is-past { background: rgba(127,127,127,.4); }
        .farah-schedule-hint { font-size: .8rem; opacity: .65; margin-top: .6rem; }
        .farah-schedule-scroll { overflow: auto; max-height: 70vh; margin-top: .9rem; border: 1px solid rgba(127,127,127,.2); border-radius: .75rem; transition: opacity .15s; }
        .farah-schedule-scroll.is-loading { opacity: .55; }
        .farah-schedule-table { border-collapse: separate; border-spacing: 0; width: 100%; font-size: .85rem; }
        .farah-schedule-table th, .farah-schedule-table td { padding: .3rem; border-bottom: 1px solid rgba(127,127,127,.12); min-width: 7.5rem; text-align: center; }
        .farah-schedule-table thead th { position: sticky; top: 0; z-index: 2; background: #fff; padding: .6rem .4rem; font-weight: 700; white-space: nowrap; }
        .dark .farah-schedule-table thead th, .dark .farah-schedule-table th.farah-schedule-time { background: #18181b; }
        .farah-schedule-table th.farah-schedule-time { position: sticky; inset-inline-start: 0; z-index: 1; background: #fff; min-width: 4.5rem; width: 4.5rem; font-variant-numeric: tabular-nums; font-weight: 700; }
        .farah-schedule-table thead th.farah-schedule-time { z-index: 3; }
        .farah-schedule-time small { display: block; font-weight: 400; opacity: .55; font-size: .7rem; }
        .farah-schedule-cell { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .1rem; width: 100%; min-height: 2.9rem; padding: .3rem .4rem; border-radius: .5rem; font-size: .8rem; line-height: 1.25; border: 1px solid transparent; }
        .farah-schedule-cell.is-available { background: rgba(16,185,129,.1); color: #047857; border-color: rgba(16,185,129,.3); }
        .dark .farah-schedule-cell.is-available { color: #6ee7b7; }
        button.farah-schedule-cell.is-available:hover { background: rgba(16,185,129,.22); }
        .farah-schedule-cell.is-booked { background: rgba(178,31,209,.12); border-color: rgba(178,31,209,.35); font-weight: 600; }
        .farah-schedule-cell.is-booked.is-checked-in { background: rgba(61,139,255,.14); border-color: rgba(61,139,255,.4); }
        a.farah-schedule-cell.is-booked:hover { background: rgba(178,31,209,.22); }
        .farah-schedule-cell.is-blocked { background: rgba(245,158,11,.14); border-color: rgba(245,158,11,.4); color: #92400e; }
        .dark .farah-schedule-cell.is-blocked { color: #fcd34d; }
        button.farah-schedule-cell.is-blocked:hover { background: rgba(245,158,11,.26); }
        .farah-schedule-cell.is-past { background: rgba(127,127,127,.08); opacity: .55; }
        .farah-schedule-customer { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .farah-schedule-code { font-size: .7rem; opacity: .75; font-weight: 400; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .farah-schedule-empty { display: grid; gap: .6rem; justify-items: center; text-align: center; padding: 1.5rem .5rem; }
        .farah-schedule-empty-title { font-weight: 700; font-size: 1.05rem; }
    </style>
</x-filament-panels::page>
