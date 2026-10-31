@extends('site.layout')

@php
    $icon = function (?string $name, string $class = 'ico') {
        try {
            return svg('heroicon-o-'.($name ?: 'sparkles'), $class)->toHtml();
        } catch (\Throwable $e) {
            return svg('heroicon-o-sparkles', $class)->toHtml();
        }
    };
    $loginUrl = url('/provider/login');
    $registerUrl = url('/provider/register');
    $faqItems = __('landing.faq.items');
    $faqJson = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($item) => [
            '@type' => 'Question',
            'name' => $item['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
        ], $faqItems),
    ];
    $statIcons = ['providers' => 'building-storefront', 'venues' => 'calendar-days', 'sectors' => 'squares-2x2', 'cities' => 'map-pin'];

    // Activity types use their own icon keys (Sector.icon), drawn inline; anything else falls back to a heroicon.
    $activityIcons = [
        'billiards' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="10" r="3.5"/><path d="M11 9.2h2M11 10.8h2"/>',
        'bowling' => '<circle cx="12" cy="12" r="9"/><circle cx="9.5" cy="8.5" r=".9" fill="currentColor"/><circle cx="13" cy="7.5" r=".9" fill="currentColor"/><circle cx="12.5" cy="11" r=".9" fill="currentColor"/>',
        'gaming' => '<path d="M7 8h10a4 4 0 0 1 4 4v1.5a3.5 3.5 0 0 1-6.3 2.1L14 15h-4l-.7.6A3.5 3.5 0 0 1 3 13.5V12a4 4 0 0 1 4-4Z"/><path d="M7.5 10.5v3M6 12h3"/><circle cx="16" cy="11" r=".9" fill="currentColor"/><circle cx="18" cy="13" r=".9" fill="currentColor"/>',
        'go-kart' => '<path d="M5 21V4"/><path d="M5 4h13l-2.5 4L18 12H5"/><path d="M9.5 4v8M14 4v8M5 8h10.5"/>',
        'shooting' => '<circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2.5"/><path d="M12 2.5V6M12 18v3.5M2.5 12H6M18 12h3.5"/>',
        'table-tennis' => '<circle cx="10" cy="9.5" r="6"/><path d="m14.2 13.8 5.3 5.3"/><path d="M5.6 13.6c2.6.6 6.3-.4 8.6-3.9"/><circle cx="19" cy="5.5" r="1.5"/>',
        'darts' => '<circle cx="11" cy="13" r="8"/><circle cx="11" cy="13" r="4"/><path d="m11 13 8.5-8.5M16.5 4.5h3v3"/>',
        'courts' => '<rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="M12 5v14M3 9.5h3v5H3M21 9.5h-3v5h3"/><circle cx="12" cy="12" r="2.2"/>',
        'experiences' => '<circle cx="8" cy="12" r="4"/><path d="M12 12h9M17.5 12v3M20.5 12v2"/>',
        // two playing cards, one behind the other (baloot, jakaroo and the other sitting-room games)
        'majlis-games' => '<rect x="4" y="6.5" width="10" height="13" rx="1.6" transform="rotate(-10 9 13)"/><rect x="10" y="4.5" width="10" height="13" rx="1.6" transform="rotate(8 15 11)"/><path d="M15 9.2l1.4 1.8-1.4 1.8-1.4-1.8z"/>',
    ];
    $sectorIcon = fn (?string $key) => isset($activityIcons[$key])
        ? '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$activityIcons[$key].'</svg>'
        : $icon($key);

    // The app tour: real dark-mode screens, from the welcome pages to the booking code.
    $tour = ['welcome-1', 'welcome-2', 'welcome-3', 'sign-in', 'verify', 'home', 'explore', 'venue', 'activities', 'choose-time', 'payment', 'paid', 'booking-qr'];
@endphp

@push('head')
    <script type="application/ld+json">{!! json_encode($faqJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endpush

@section('styles')
/* Hero */
.hero { position: relative; overflow: hidden; padding: 3.5rem 0; }
.hero::before { content: ""; position: absolute; inset: 0; pointer-events: none; background: radial-gradient(44rem 30rem at 22% 50%, rgba(123,47,247,.22), transparent 65%); }
.hero-grid { position: relative; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4rem; align-items: center; }
.hero h1 { font-size: clamp(2rem, 5vw, 3.5rem); line-height: 1.2; margin: 1.1rem 0 1rem; font-weight: 700; letter-spacing: -.01em; }
.hero p.lead { font-size: 1.12rem; color: var(--muted); max-width: 36rem; margin: 0 0 1.5rem; }
.points { display: grid; gap: .6rem; margin: 0 0 2rem; padding: 0; list-style: none; }
.points li { display: flex; align-items: center; gap: .6rem; }
.points .ico { width: 1.2rem; height: 1.2rem; color: var(--accent); }
.cta-row { display: flex; flex-wrap: wrap; gap: .75rem; }
.stores { display: flex; flex-wrap: wrap; gap: 1rem .75rem; margin-top: 2rem; }
.store { display: inline-flex; align-items: center; gap: .7rem; padding: .6rem 1.1rem; border-radius: .9rem; background: rgba(255,255,255,.05); border: 1px solid var(--line); min-width: 11rem; position: relative; cursor: default; }
.store > svg { width: 1.7rem; height: 1.7rem; flex-shrink: 0; opacity: .85; }
.store small { display: block; font-size: .7rem; color: var(--muted); line-height: 1.1; }
.store b { display: block; font-size: 1.05rem; line-height: 1.2; }
.store .soon { position: absolute; top: -.7rem; inset-inline-end: -.4rem; font-size: .72rem; font-weight: 700; padding: .1rem .55rem; border-radius: 999px; background: var(--accent); color: #fff; }
.phone { position: relative; height: min(calc(100svh - 9rem), 40rem); aspect-ratio: 9 / 20; border-radius: 2.6rem; border: 7px solid #2a2833; background: #000; box-shadow: 0 30px 60px -20px rgba(0,0,0,.7); overflow: hidden; }
.phone img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: 0; transition: opacity .5s ease; }
.phone img.is-on { opacity: 1; }

/* Stats strip */
.stats { padding: 0 0 4.5rem; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr)); gap: 1px; margin: 0; border-radius: var(--radius); overflow: hidden; border: 1px solid var(--line); background: var(--line); }
.stat { display: flex; flex-direction: column-reverse; align-items: center; justify-content: center; gap: .15rem; padding: 1.5rem 1rem; background: var(--bg-2); text-align: center; }
.stat dd { margin: 0; font-size: clamp(1.8rem, 4vw, 2.4rem); font-weight: 700; line-height: 1.1; }
.stat dt { color: var(--muted); font-size: .92rem; display: inline-flex; align-items: center; gap: .4rem; }
.stat dt .ico { width: 1.1rem; height: 1.1rem; color: var(--accent); }

/* Sections */
section.block { padding: 5rem 0; }
section.block:nth-of-type(odd) { background: var(--bg-2); }
.sec-head { text-align: center; max-width: 42rem; margin: 0 auto 3rem; }
.sec-head h2 { font-size: clamp(1.6rem, 3.4vw, 2.5rem); line-height: 1.3; margin: 0 0 .6rem; }
.sec-head p { color: var(--muted); margin: 0; font-size: 1.05rem; }
.eyebrow { display: inline-block; margin-bottom: .6rem; font-size: .8rem; font-weight: 700; letter-spacing: .08em; }

.steps { display: grid; grid-template-columns: repeat(5, 1fr); gap: 1rem; margin: 0; padding: 0; list-style: none; }
.step { padding-top: 1rem; border-top: 2px solid var(--line); }
.step-num { font-size: 1.6rem; font-weight: 700; line-height: 1; color: var(--accent); margin-bottom: .75rem; }
.step h3 { margin: 0 0 .35rem; font-size: 1.05rem; }
.step p { margin: 0; color: var(--muted); font-size: .92rem; }

.features { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.1rem; }
.feature { padding: 1.7rem; border-radius: var(--radius); background: var(--card); border: 1px solid var(--line); }
.feature-icon { margin-bottom: 1rem; color: var(--accent); }
.feature h3 { margin: 0 0 .4rem; font-size: 1.12rem; }
.feature p { margin: 0; color: var(--muted); font-size: .95rem; }

.sectors { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 210px), 1fr)); gap: 1rem; }
.sector { padding: 1.5rem; border-radius: var(--radius); border: 1px solid var(--line); background: var(--card); }
.sector-icon { margin-bottom: .8rem; color: var(--accent); }
.sector h3 { margin: 0 0 .25rem; font-size: 1.08rem; }
.sector .count { color: var(--muted); font-size: .85rem; }
.chips { display: flex; flex-wrap: wrap; gap: .35rem; margin: .8rem 0 0; padding: 0; list-style: none; }
.chip { font-size: .78rem; padding: .15rem .6rem; border-radius: 999px; background: rgba(255,255,255,.07); color: var(--muted); }

.plans { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 230px), 1fr)); gap: 1rem; align-items: stretch; }
.plan { display: flex; flex-direction: column; padding: 1.6rem; border-radius: var(--radius); background: var(--card); border: 1px solid var(--line); transition: border-color .2s, transform .2s; }
.plan h3 { margin: 0 0 .5rem; font-size: 1.15rem; }
.plan-price { font-size: 1.9rem; font-weight: 700; line-height: 1.2; margin-bottom: .6rem; }
.plan-price small { font-size: .85rem; font-weight: 400; color: var(--muted); }
.plan > p { margin: 0 0 1rem; color: var(--muted); font-size: .92rem; }
.plan ul { list-style: none; padding: 0; margin: 0 0 1.4rem; display: grid; gap: .5rem; font-size: .93rem; }
.plan li { display: flex; gap: .5rem; align-items: flex-start; }
.plan li .ico { width: 1.1rem; height: 1.1rem; flex-shrink: 0; margin-top: .2rem; color: var(--accent); }
.plan li small { color: var(--muted); }
.plan .btn { margin-top: auto; justify-content: center; }
.plans-note { text-align: center; color: var(--muted); font-size: .9rem; margin: 1.5rem 0 0; }

.faq { max-width: 50rem; margin: 0 auto; display: grid; gap: .75rem; }
.faq details { border: 1px solid var(--line); border-radius: 1rem; background: var(--card); transition: border-color .2s; }
.faq summary { cursor: pointer; list-style: none; padding: 1.1rem 1.25rem; font-weight: 600; display: flex; justify-content: space-between; align-items: center; gap: 1rem; border-radius: 1rem; }
.faq summary::-webkit-details-marker { display: none; }
.faq summary::after { content: "+"; flex-shrink: 0; font-size: 1.4rem; line-height: 1; color: var(--accent); transition: transform .2s; }
.faq details[open] summary::after { transform: rotate(45deg); }
.faq details p { margin: 0; padding: 0 1.25rem 1.2rem; color: var(--muted); }
.faq-more { text-align: center; margin: 1.75rem 0 0; color: var(--muted); }
.faq-more a { color: var(--text); border-bottom: 1px solid var(--accent); }
.faq-more a:hover { color: var(--accent); }

.final { text-align: center; padding: 4rem 1.5rem; border-radius: 2rem; background: var(--brand-soft); border: 1px solid var(--line); }
.final h2 { font-size: clamp(1.6rem, 3.4vw, 2.5rem); margin: 0 0 .5rem; line-height: 1.3; }
.final p { color: var(--text); opacity: .85; margin: 0 0 1.8rem; }
.final .cta-row { justify-content: center; }

@media (max-width: 1024px) {
    .steps { grid-template-columns: repeat(2, 1fr); }
    .features { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 820px) {
    .hero-grid { grid-template-columns: minmax(0, 1fr); gap: 2.5rem; }
    .phone { height: auto; width: min(17rem, 100%); justify-self: center; }
    section.block { padding: 4rem 0; }
}
@media (max-width: 600px) {
    .steps, .features { grid-template-columns: 1fr; }
    .cta-row .btn { width: 100%; }
    .store { min-width: 0; flex: 1 1 10rem; }
    .final { padding: 3rem 1.1rem; }
}
@endsection

@section('content')
    <section class="hero" id="app" aria-labelledby="hero-title">
        <div class="wrap hero-grid">
            <div>
                <span class="badge"><span class="badge-dot" aria-hidden="true"></span>{{ __('landing.hero.badge') }}</span>
                <h1 id="hero-title">{{ __('landing.app.title') }}</h1>
                <p class="lead">{{ __('landing.app.subtitle') }}</p>
                <ul class="points">
                    @foreach (['point_1', 'point_2', 'point_3'] as $point)
                        <li>{!! $icon('check-circle') !!}{{ __('landing.app.'.$point) }}</li>
                    @endforeach
                </ul>
                <div class="cta-row">
                    <a class="btn btn-primary" href="{{ $registerUrl }}">{{ __('landing.hero.cta_join') }}</a>
                    <a class="btn btn-ghost" href="{{ $loginUrl }}">{{ __('landing.hero.cta_sign_in') }}</a>
                </div>
                    {{-- Placeholders until the app is published: not links, clearly marked "coming soon". --}}
                    <div class="stores">
                        <div class="store">
                            <span class="soon">{{ __('landing.app.soon') }}</span>
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.37 12.5c-.02-2.3 1.88-3.4 1.96-3.46-1.07-1.56-2.73-1.78-3.32-1.8-1.41-.14-2.76.83-3.47.83-.72 0-1.82-.81-3-.79-1.54.02-2.96.9-3.76 2.28-1.6 2.78-.41 6.9 1.15 9.16.76 1.1 1.67 2.34 2.86 2.3 1.15-.05 1.58-.74 2.97-.74 1.38 0 1.77.74 2.98.72 1.23-.02 2.01-1.12 2.76-2.23.87-1.28 1.23-2.51 1.25-2.58-.03-.01-2.4-.92-2.42-3.66ZM14.1 5.73c.63-.77 1.06-1.83.94-2.9-.91.04-2.01.61-2.66 1.37-.58.67-1.1 1.76-.96 2.8 1.02.08 2.05-.52 2.68-1.27Z"/></svg>
                            <span><small>{{ __('landing.app.download_on') }}</small><b class="ltr">{{ __('landing.app.app_store') }}</b></span>
                        </div>
                        <div class="store">
                            <span class="soon">{{ __('landing.app.soon') }}</span>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#34A853" d="M3.6 2.2 13.4 12l-9.8 9.8c-.35-.2-.6-.6-.6-1.1V3.3c0-.5.25-.9.6-1.1Z"/><path fill="#FBBC04" d="m16.8 8.6-3.4 3.4 3.4 3.4 3.9-2.2c.8-.45.8-1.6 0-2.05l-3.9-2.55Z"/><path fill="#EA4335" d="M13.4 12 3.6 21.8c.35.2.8.2 1.2 0l12-6.4-3.4-3.4Z"/><path fill="#4285F4" d="M4.8 2.2c-.4-.2-.85-.2-1.2 0L13.4 12l3.4-3.4-12-6.4Z"/></svg>
                            <span><small>{{ __('landing.app.get_it_on') }}</small><b class="ltr">{{ __('landing.app.google_play') }}</b></span>
                        </div>
                    </div>
            </div>
            <div class="phone" id="app-tour" role="img" aria-label="{{ __('landing.app.screenshot_alt') }}">
                @foreach ($tour as $frame)
                    <img src="{{ asset('images/app-dark/'.$frame.'.webp') }}" alt="" width="540" height="1200" @class(['is-on' => $loop->first])>
                @endforeach
            </div>
        </div>
    </section>

    @if (! empty($stats))
        <section class="stats" aria-label="{{ __('landing.stats.label') }}">
            <div class="wrap">
                <dl class="stats-grid">
                    @foreach ($stats as $key => $value)
                        <div class="stat">
                            <dt>{!! $icon($statIcons[$key] ?? 'sparkles') !!}{{ __('landing.stats.'.$key) }}</dt>
                            <dd class="ltr">{{ number_format($value) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </section>
    @endif

    <section class="block" id="how" aria-labelledby="how-title">
        <div class="wrap">
            <div class="sec-head">
                <h2 id="how-title">{{ __('landing.how.title') }}</h2>
                <p>{{ __('landing.how.subtitle') }}</p>
            </div>
            <ol class="steps">
                @foreach (__('landing.how.steps') as $i => $step)
                    <li class="step">
                        <div class="step-num" aria-hidden="true">{{ $i + 1 }}</div>
                        <h3>{{ $step['title'] }}</h3>
                        <p>{{ $step['body'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    <section class="block" id="features" aria-labelledby="features-title">
        <div class="wrap">
            <div class="sec-head">
                <h2 id="features-title">{{ __('landing.features.title') }}</h2>
                <p>{{ __('landing.features.subtitle') }}</p>
            </div>
            <div class="features">
                @foreach (__('landing.features.items') as $feature)
                    <div class="feature">
                        <div class="feature-icon">{!! $icon($feature['icon']) !!}</div>
                        <h3>{{ $feature['title'] }}</h3>
                        <p>{{ $feature['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="block" id="sectors" aria-labelledby="sectors-title">
        <div class="wrap">
            <div class="sec-head">
                <h2 id="sectors-title">{{ __('landing.sectors.title') }}</h2>
                <p>{{ __('landing.sectors.subtitle') }}</p>
            </div>
            @if ($sectors->isEmpty())
                <p class="sec-head">{{ __('landing.sectors.empty') }}</p>
            @else
                <div class="sectors">
                    @foreach ($sectors as $sector)
                        <div class="sector">
                            <div class="sector-icon">{!! $sectorIcon($sector->icon) !!}</div>
                            <h3>{{ $sector->tr('name') }}</h3>
                            <div class="count">{{ trans_choice('landing.sectors.categories', $sector->categories->count(), ['count' => $sector->categories->count()]) }}</div>
                            @if ($sector->categories->isNotEmpty())
                                <ul class="chips">
                                    @foreach ($sector->categories->take(4) as $category)
                                        <li class="chip">{{ $category->tr('name') }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- Plans for store owners: the choice is made here or on the sign-up form, before the account exists. --}}
    @if ($plans->isNotEmpty())
        <section class="block" id="plans" aria-labelledby="plans-title">
            <div class="wrap">
                <div class="sec-head">
                    <h2 id="plans-title">{{ __('landing.plans.title') }}</h2>
                    <p>{{ __('landing.plans.subtitle') }}</p>
                </div>
                <div class="plans">
                    @foreach ($plans as $plan)
                        <div @class(['plan', 'is-free' => $plan->is_free])>
                            <h3>{{ $plan->tr('name') }}</h3>
                            <div class="plan-price">
                                @if ($plan->is_free)
                                    {{ __('landing.plans.free') }}
                                @else
                                    <span class="ltr">{{ number_format($plan->price) }}</span> {{ __('landing.plans.currency') }}
                                    <small>/ {{ __('landing.plans.days', ['days' => $plan->duration_days]) }}</small>
                                @endif
                            </div>
                            @if ($plan->tr('description'))
                                <p>{{ $plan->tr('description') }}</p>
                            @endif
                            <ul>
                                @foreach ($plan->items as $item)
                                    <li>
                                        {!! $icon('check-circle') !!}
                                        <span>
                                            {{ $item->feature->getLabel() }}@if ($item->value) ({{ __('landing.plans.up_to', ['count' => $item->value]) }})@endif
                                            @if (! $plan->is_free && $item->days && $item->days < $plan->duration_days)
                                                <small>· {{ __('landing.plans.days', ['days' => $item->days]) }}</small>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                            <a @class(['btn', 'btn-primary' => ! $plan->is_free, 'btn-ghost' => $plan->is_free]) href="{{ $registerUrl }}?plan={{ $plan->id }}">
                                {{ $plan->is_free ? __('landing.plans.start_free') : __('landing.plans.choose') }}
                            </a>
                        </div>
                    @endforeach
                </div>
                <p class="plans-note">{{ __('landing.plans.note') }}</p>
            </div>
        </section>
    @endif

    <section class="block" id="faq" aria-labelledby="faq-title">
        <div class="wrap">
            <div class="sec-head"><h2 id="faq-title">{{ __('landing.faq.title') }}</h2></div>
            <div class="faq">
                @foreach ($faqItems as $i => $item)
                    <details @if ($i === 0) open @endif>
                        <summary>{{ $item['q'] }}</summary>
                        <p>{{ $item['a'] }}</p>
                    </details>
                @endforeach
            </div>
            <p class="faq-more">{{ __('site.page.help_title') }} <a href="{{ route('site.contact') }}">{{ __('site.nav.contact') }}</a></p>
        </div>
    </section>

    <section class="block" aria-labelledby="cta-title">
        <div class="wrap">
            <div class="final">
                <h2 id="cta-title">{{ __('landing.cta.title') }}</h2>
                <p>{{ __('landing.cta.subtitle') }}</p>
                <div class="cta-row">
                    <a class="btn btn-primary" href="{{ $registerUrl }}">{{ __('landing.join') }}</a>
                    <a class="btn btn-ghost" href="{{ $loginUrl }}">{{ __('landing.sign_in') }}</a>
                </div>
            </div>
        </div>
    </section>
    {{-- Plays the app tour in the hero; it stops while the phone is off screen. --}}
    <script>
        (() => {
            const tour = document.getElementById('app-tour');
            if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            const frames = [...tour.querySelectorAll('img')];
            let current = 0, timer = null;
            new IntersectionObserver(([entry]) => {
                clearInterval(timer);
                if (!entry.isIntersecting) return;
                timer = setInterval(() => {
                    frames[current].classList.remove('is-on');
                    current = (current + 1) % frames.length;
                    frames[current].classList.add('is-on');
                }, 2600);
            }, { threshold: .4 }).observe(tour);
        })();
    </script>
@endsection
