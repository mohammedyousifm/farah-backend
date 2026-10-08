<x-filament-widgets::widget>
    <x-filament::section :heading="$title" :description="$description" icon="heroicon-o-rocket-launch">
        <div class="farah-onboarding-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress }}">
            <div class="farah-onboarding-progress-bar" style="width: {{ $progress }}%"></div>
        </div>
        <p class="farah-onboarding-progress-label">
            {{ $readyForReview ? $readyLabel : __('provider.onboarding.progress', ['percent' => $progress]) }}
        </p>

        <ol class="farah-onboarding">
            @foreach ($steps as $i => $step)
                <li @class(['is-done' => $step['done'], 'is-review' => $step['key'] === 'review'])>
                    <span class="farah-onboarding-num" aria-hidden="true">
                        @if ($step['done'])
                            ✓
                        @elseif ($step['key'] === 'review')
                            ⏳
                        @else
                            {{ $i + 1 }}
                        @endif
                    </span>
                    <div class="farah-onboarding-body">
                        <div class="farah-onboarding-label">
                            {{ $step['label'] }}
                            <span class="fi-sr-only">— {{ $step['done'] ? __('provider.onboarding.done') : __('provider.onboarding.todo') }}</span>
                        </div>
                        @if (! empty($step['detail']))
                            <div class="farah-onboarding-detail">{{ $step['detail'] }}</div>
                        @endif
                        @if (! empty($step['items']))
                            <ul class="farah-onboarding-items">
                                @foreach ($step['items'] as $item)
                                    <li class="is-{{ $item['state'] }}">
                                        <span class="farah-onboarding-dot" aria-hidden="true"></span>
                                        <span>{{ $item['label'] }}</span>
                                        <span class="farah-onboarding-item-status">{{ $item['status'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    @if ($step['url'] && ! $step['done'])
                        <x-filament::button :href="$step['url']" tag="a" size="sm" color="primary">{{ __('provider.onboarding.open') }}</x-filament::button>
                    @elseif ($step['url'])
                        <x-filament::link :href="$step['url']" size="sm" color="gray">{{ __('provider.onboarding.view') }}</x-filament::link>
                    @endif
                </li>
            @endforeach
        </ol>
        @if ($rejectedDocuments > 0)
            <p class="farah-onboarding-warn">{{ __('provider.onboarding.rejected_documents', ['count' => $rejectedDocuments]) }}</p>
        @endif
    </x-filament::section>

    <style>
        .farah-onboarding-progress { height: .5rem; border-radius: 999px; background: rgba(127,127,127,.15); overflow: hidden; }
        .farah-onboarding-progress-bar { height: 100%; border-radius: 999px; background: var(--primary-600); transition: width .4s; }
        .farah-onboarding-progress-label { font-size: .85rem; opacity: .75; margin: .4rem 0 1rem; }
        .farah-onboarding { display: grid; gap: .6rem; }
        .farah-onboarding > li { display: flex; align-items: flex-start; gap: .75rem; padding: .8rem .9rem; border-radius: .75rem; border: 1px solid rgba(127,127,127,.2); flex-wrap: wrap; }
        .farah-onboarding > li.is-done { border-color: rgba(16,185,129,.35); background: rgba(16,185,129,.05); }
        .farah-onboarding-num { width: 1.9rem; height: 1.9rem; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; background: rgba(127,127,127,.15); flex-shrink: 0; }
        .farah-onboarding > li.is-done .farah-onboarding-num { background: #0F7B55; color: #fff; }
        .farah-onboarding-body { flex: 1; min-width: 12rem; }
        .farah-onboarding-label { font-weight: 600; }
        .farah-onboarding-detail { font-size: .85rem; opacity: .75; margin-top: .15rem; }
        .farah-onboarding-items { display: grid; gap: .25rem; margin-top: .4rem; font-size: .85rem; }
        .farah-onboarding-items li { display: flex; align-items: center; gap: .5rem; }
        .farah-onboarding-dot { width: .55rem; height: .55rem; border-radius: 999px; background: rgba(127,127,127,.5); flex-shrink: 0; }
        .farah-onboarding-items li.is-done .farah-onboarding-dot { background: #10b981; }
        .farah-onboarding-items li.is-waiting .farah-onboarding-dot { background: #f59e0b; }
        .farah-onboarding-items li.is-problem .farah-onboarding-dot { background: #e11d48; }
        .farah-onboarding-item-status { opacity: .65; margin-inline-start: auto; }
        .farah-onboarding-warn { margin-top: .75rem; color: #e11d48; font-size: .9rem; }
    </style>
</x-filament-widgets::widget>
