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
    ];
    $sectorIcon = fn (?string $key) => isset($activityIcons[$key])
        ? '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$activityIcons[$key].'</svg>'
        : $icon($key);

    // Hero mock: one evening of a billiards hall, per table.
    $mockHours = ['18:00', '19:00', '20:00', '21:00', '22:00'];
    $mockGrid = [
        ['booked', 'booked', 'free', 'free', 'booked'],
        ['free', 'booked', 'booked', 'free', 'free'],
        ['free', 'free', 'picked', 'picked', 'free'],
        ['booked', 'free', 'free', 'booked', 'booked'],
    ];
@endphp

@push('head')
    <script type="application/ld+json">{!! json_encode($faqJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endpush

@section('styles')
/* Hero */
.hero { position: relative; overflow: hidden; padding: 5rem 0 4.5rem; }
.hero::before { content: ""; position: absolute; inset: 0; pointer-events: none;
    background:
        radial-gradient(38rem 26rem at 12% 8%, rgba(249,178,51,.18), transparent 60%),
        radial-gradient(40rem 28rem at 88% 18%, rgba(230,0,126,.22), transparent 60%),
        radial-gradient(44rem 30rem at 50% 110%, rgba(61,139,255,.20), transparent 60%); }
.hero-grid { position: relative; display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, .85fr); gap: 3rem; align-items: center; }
.hero h1 { font-size: clamp(2rem, 5vw, 3.7rem); line-height: 1.2; margin: 1.1rem 0 1rem; font-weight: 700; letter-spacing: -.01em; }
.hero h1 span { display: block; }
.hero p.lead { font-size: 1.12rem; color: var(--muted); max-width: 36rem; margin: 0 0 2rem; }
.cta-row { display: flex; flex-wrap: wrap; gap: .75rem; }
.points { display: flex; flex-wrap: wrap; gap: .6rem 1.25rem; margin: 1.75rem 0 0; padding: 0; list-style: none; font-size: .9rem; color: var(--muted); }
.points li { display: inline-flex; align-items: center; gap: .4rem; }
.points .ico { width: 1.1rem; height: 1.1rem; color: #34d399; }

.mock { position: relative; border-radius: 1.6rem; padding: 1.4rem; background: linear-gradient(160deg, rgba(255,255,255,.08), rgba(255,255,255,.02)); border: 1px solid var(--line); box-shadow: 0 40px 80px -30px rgba(123,47,247,.45); }
.mock::before { content: ""; position: absolute; inset: -1px; border-radius: inherit; padding: 1px; background: var(--grad); -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor; mask-composite: exclude; opacity: .55; pointer-events: none; }
.mock-head { display: flex; justify-content: space-between; align-items: center; gap: .75rem; color: var(--muted); font-size: .85rem; }
.mock-head b { color: var(--text); font-size: 1rem; }
.days { display: flex; gap: .4rem; margin: .9rem 0 1rem; overflow: hidden; }
.day { font-size: .78rem; padding: .3rem .75rem; border-radius: 999px; background: rgba(255,255,255,.06); border: 1px solid var(--line); color: var(--muted); white-space: nowrap; }
.day.on { background: var(--grad); color: #fff; border-color: transparent; font-weight: 600; }
.slots { display: grid; grid-template-columns: auto repeat(5, minmax(0, 1fr)); gap: .4rem; align-items: center; margin-bottom: .8rem; }
.slots small { color: var(--muted); font-size: .72rem; text-align: center; white-space: nowrap; }
.slots small.res { text-align: start; padding-inline-end: .35rem; }
.slot { display: block; height: 2rem; border-radius: .55rem; background: rgba(52,211,153,.14); border: 1px solid rgba(52,211,153,.4); }
.slot.booked { background: repeating-linear-gradient(135deg, rgba(255,255,255,.04) 0 6px, rgba(255,255,255,.1) 6px 12px); border-color: var(--line); }
.slot.picked { background: linear-gradient(135deg, var(--magenta), var(--purple)); border-color: transparent; box-shadow: 0 6px 16px -6px rgba(230,0,126,.8); }
.legend { display: flex; flex-wrap: wrap; gap: .4rem 1rem; font-size: .75rem; color: var(--muted); margin-bottom: 1.1rem; }
.legend span { display: inline-flex; align-items: center; gap: .4rem; }
.legend .slot { width: .9rem; height: .9rem; border-radius: .3rem; }
.mock-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: .6rem; }
.mock-stat { background: rgba(255,255,255,.05); border: 1px solid var(--line); border-radius: .9rem; padding: .7rem; min-width: 0; }
.mock-stat b { display: block; font-size: 1.2rem; }
.mock-stat small { color: var(--muted); font-size: .72rem; }
.confirm { position: absolute; inset-inline-start: -2.2rem; bottom: -1.6rem; background: #fff; color: #111; border-radius: 1rem; padding: .7rem 1rem; display: flex; align-items: center; gap: .7rem; box-shadow: 0 20px 40px -12px rgba(0,0,0,.6); font-size: .85rem; font-weight: 600; }
.confirm .ico { color: #059669; width: 1.6rem; height: 1.6rem; }
.confirm small { display: block; font-size: .78rem; font-weight: 400; color: #444; }

/* Stats strip */
.stats { padding: 0 0 4.5rem; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr)); gap: 1px; margin: 0; border-radius: var(--radius); overflow: hidden; border: 1px solid var(--line); background: var(--line); }
.stat { display: flex; flex-direction: column-reverse; align-items: center; justify-content: center; gap: .15rem; padding: 1.5rem 1rem; background: var(--bg-2); text-align: center; }
.stat dd { margin: 0; font-size: clamp(1.8rem, 4vw, 2.4rem); font-weight: 700; line-height: 1.1; }
.stat dt { color: var(--muted); font-size: .92rem; display: inline-flex; align-items: center; gap: .4rem; }
.stat dt .ico { width: 1.1rem; height: 1.1rem; color: var(--orange); }

/* Sections */
section.block { padding: 5rem 0; border-top: 1px solid var(--line); }
.sec-head { text-align: center; max-width: 42rem; margin: 0 auto 3rem; }
.sec-head h2 { font-size: clamp(1.6rem, 3.4vw, 2.5rem); line-height: 1.3; margin: 0 0 .6rem; }
.sec-head p { color: var(--muted); margin: 0; font-size: 1.05rem; }
.eyebrow { display: inline-block; margin-bottom: .6rem; font-size: .8rem; font-weight: 700; letter-spacing: .08em; }

.steps { display: grid; grid-template-columns: repeat(5, 1fr); gap: 1rem; margin: 0; padding: 0; list-style: none; }
.step { position: relative; padding: 1.5rem 1.2rem; border-radius: var(--radius); background: var(--card); border: 1px solid var(--line); }
.step-num { width: 2.4rem; height: 2.4rem; border-radius: .8rem; display: grid; place-items: center; font-weight: 700; background: linear-gradient(135deg, var(--magenta), var(--purple)); margin-bottom: 1rem; }
.step h3 { margin: 0 0 .35rem; font-size: 1.05rem; }
.step p { margin: 0; color: var(--muted); font-size: .92rem; }

.features { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.1rem; }
.feature { padding: 1.7rem; border-radius: var(--radius); background: var(--card); border: 1px solid var(--line); transition: border-color .2s, transform .2s; }
.feature:hover { border-color: rgba(230,0,126,.45); transform: translateY(-2px); }
.feature-icon { width: 3rem; height: 3rem; border-radius: 1rem; display: grid; place-items: center; margin-bottom: 1.1rem; background: rgba(230,0,126,.14); color: #ff6cb8; }
.feature:nth-child(3n+2) .feature-icon { background: rgba(123,47,247,.16); color: #b39bff; }
.feature:nth-child(3n+3) .feature-icon { background: rgba(61,139,255,.16); color: #8bbaff; }
.feature h3 { margin: 0 0 .4rem; font-size: 1.12rem; }
.feature p { margin: 0; color: var(--muted); font-size: .95rem; }

.sectors { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 210px), 1fr)); gap: 1rem; }
.sector { position: relative; overflow: hidden; padding: 1.5rem; border-radius: var(--radius); border: 1px solid var(--line); background: linear-gradient(160deg, rgba(255,255,255,.05), rgba(255,255,255,.01)); transition: border-color .2s, transform .2s; }
.sector::before { content: ""; position: absolute; inset-inline: 0; top: 0; height: 3px; background: var(--grad); opacity: .55; transition: opacity .2s; }
.sector:hover { border-color: rgba(123,47,247,.45); transform: translateY(-2px); }
.sector:hover::before { opacity: 1; }
.sector-icon { width: 2.8rem; height: 2.8rem; border-radius: .9rem; display: grid; place-items: center; background: var(--grad); color: #fff; margin-bottom: 1rem; }
.sector h3 { margin: 0 0 .25rem; font-size: 1.08rem; }
.sector .count { color: var(--muted); font-size: .85rem; }
.chips { display: flex; flex-wrap: wrap; gap: .35rem; margin: .8rem 0 0; padding: 0; list-style: none; }
.chip { font-size: .78rem; padding: .15rem .6rem; border-radius: 999px; background: rgba(255,255,255,.07); color: var(--muted); }

.app { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 3rem; align-items: center; padding: 3rem; border-radius: 2rem; border: 1px solid var(--line);
    background: radial-gradient(30rem 20rem at 100% 0%, rgba(123,47,247,.28), transparent 60%), radial-gradient(30rem 20rem at 0% 100%, rgba(249,178,51,.16), transparent 60%), var(--bg-2); }
.app h2 { font-size: clamp(1.6rem, 3vw, 2.3rem); margin: 0 0 .7rem; line-height: 1.3; }
.app p { color: var(--muted); margin: 0 0 1.4rem; }
.app ul { list-style: none; padding: 0; margin: 0 0 1.8rem; display: grid; gap: .6rem; }
.app li { display: flex; gap: .6rem; align-items: center; }
.app li .ico { width: 1.2rem; height: 1.2rem; color: var(--orange); }
.stores { display: flex; flex-wrap: wrap; gap: 1rem .75rem; padding-top: .5rem; }
.store { display: inline-flex; align-items: center; gap: .7rem; padding: .6rem 1.1rem; border-radius: .9rem; background: #000; border: 1px dashed rgba(255,255,255,.28); min-width: 11rem; position: relative; cursor: default; }
.store > svg { width: 1.7rem; height: 1.7rem; flex-shrink: 0; opacity: .85; }
.store small { display: block; font-size: .7rem; color: var(--muted); line-height: 1.1; }
.store b { display: block; font-size: 1.05rem; line-height: 1.2; }
.store .soon { position: absolute; top: -.7rem; inset-inline-end: -.4rem; font-size: .72rem; font-weight: 700; padding: .1rem .55rem; border-radius: 999px; background: var(--orange); color: #111; }
.phone { justify-self: center; width: min(15rem, 100%); aspect-ratio: 9 / 18.5; border-radius: 2.4rem; border: 8px solid #1d1d25; background: #0f0f15; padding: 1rem .8rem; box-shadow: 0 30px 60px -20px rgba(0,0,0,.8); display: flex; flex-direction: column; gap: .6rem; }
.phone-card { border-radius: 1rem; height: 6.2rem; flex-shrink: 0; background: linear-gradient(135deg, var(--orange), var(--magenta)); position: relative; overflow: hidden; }
.phone-card:nth-child(3) { background: linear-gradient(135deg, var(--purple), var(--blue)); }
.phone-card:nth-child(4) { background: linear-gradient(135deg, var(--magenta), var(--purple)); }
.phone-card span { position: absolute; bottom: .6rem; inset-inline-start: .7rem; font-size: .75rem; font-weight: 700; text-shadow: 0 1px 4px rgba(0,0,0,.35); }
.phone-bar { height: .55rem; border-radius: 999px; background: rgba(255,255,255,.08); flex-shrink: 0; }
.phone-bar.short { width: 60%; }

.faq { max-width: 50rem; margin: 0 auto; display: grid; gap: .75rem; }
.faq details { border: 1px solid var(--line); border-radius: 1rem; background: var(--card); transition: border-color .2s; }
.faq details[open] { border-color: rgba(230,0,126,.35); }
.faq summary { cursor: pointer; list-style: none; padding: 1.1rem 1.25rem; font-weight: 600; display: flex; justify-content: space-between; align-items: center; gap: 1rem; border-radius: 1rem; }
.faq summary::-webkit-details-marker { display: none; }
.faq summary::after { content: "+"; flex-shrink: 0; font-size: 1.4rem; line-height: 1; color: #ff4fa6; transition: transform .2s; }
.faq details[open] summary::after { transform: rotate(45deg); }
.faq details p { margin: 0; padding: 0 1.25rem 1.2rem; color: var(--muted); }
.faq-more { text-align: center; margin: 1.75rem 0 0; color: var(--muted); }
.faq-more a { color: var(--text); border-bottom: 1px solid rgba(249,178,51,.6); }
.faq-more a:hover { color: var(--orange); }

.final { text-align: center; padding: 4rem 1.5rem; border-radius: 2rem; position: relative; overflow: hidden; background: linear-gradient(120deg, rgba(249,178,51,.18), rgba(230,0,126,.22), rgba(123,47,247,.22), rgba(61,139,255,.18)); border: 1px solid var(--line); }
.final h2 { font-size: clamp(1.6rem, 3.4vw, 2.5rem); margin: 0 0 .5rem; line-height: 1.3; }
.final p { color: var(--text); opacity: .85; margin: 0 0 1.8rem; }
.final .cta-row { justify-content: center; }

@media (max-width: 1024px) {
    .steps { grid-template-columns: repeat(2, 1fr); }
    .features { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 820px) {
    .hero { padding: 3.5rem 0; }
    .hero-grid, .app { grid-template-columns: minmax(0, 1fr); }
    .mock { margin-inline: 1.5rem .5rem; margin-bottom: 1.5rem; }
    .app { padding: 2rem 1.4rem; }
    section.block { padding: 4rem 0; }
}
@media (max-width: 600px) {
    .steps, .features { grid-template-columns: 1fr; }
    .cta-row .btn { width: 100%; }
    .mock { margin-inline: .75rem 0; padding: 1.1rem 1.1rem 2.8rem; }
    .mock-stats { grid-template-columns: 1fr 1fr; }
    .mock-stat:last-child { grid-column: span 2; }
    .confirm { inset-inline-start: -.75rem; }
    .slot { height: 1.7rem; }
    .store { min-width: 0; flex: 1 1 10rem; }
    .final { padding: 3rem 1.1rem; }
}
@endsection

@section('content')
    <section class="hero" aria-labelledby="hero-title">
        <div class="wrap hero-grid">
            <div>
                <span class="badge"><span class="badge-dot" aria-hidden="true"></span>{{ __('landing.hero.badge') }}</span>
                <h1 id="hero-title">
                    <span>{{ __('landing.hero.title_1') }}</span>
                    <span class="grad-text">{{ __('landing.hero.title_2') }}</span>
                </h1>
                <p class="lead">{{ __('landing.hero.subtitle') }}</p>
                <div class="cta-row">
                    <a class="btn btn-primary" href="{{ $registerUrl }}">{{ __('landing.hero.cta_join') }}</a>
                    <a class="btn btn-ghost" href="{{ $loginUrl }}">{{ __('landing.hero.cta_sign_in') }}</a>
                </div>
                <ul class="points">
                    @foreach (['point_1', 'point_2', 'point_3'] as $point)
                        <li>{!! $icon('check-circle') !!}{{ __('landing.hero.'.$point) }}</li>
                    @endforeach
                </ul>
            </div>

            <div class="mock" aria-hidden="true">
                <div class="mock-head"><b>{{ __('landing.hero.card_venue') }}</b><span>{{ __('landing.hero.card_price') }}</span></div>
                <div class="days">
                    @foreach (__('landing.hero.card_days') as $i => $day)
                        <span @class(['day', 'on' => $i === 0])>{{ $day }}</span>
                    @endforeach
                </div>
                <div class="slots">
                    <span></span>
                    @foreach ($mockHours as $hour)
                        <small class="ltr">{{ $hour }}</small>
                    @endforeach
                    @foreach ($mockGrid as $n => $row)
                        <small class="res">{{ __('landing.hero.card_resource', ['n' => $n + 1]) }}</small>
                        @foreach ($row as $state)
                            <i class="slot {{ $state }}"></i>
                        @endforeach
                    @endforeach
                </div>
                <div class="legend">
                    @foreach (['free', 'booked', 'picked'] as $state)
                        <span><i class="slot {{ $state }}"></i>{{ __('landing.hero.legend_'.$state) }}</span>
                    @endforeach
                </div>
                <div class="mock-stats">
                    <div class="mock-stat"><b class="ltr">38</b><small>{{ __('landing.hero.card_bookings') }}</small></div>
                    <div class="mock-stat"><b class="ltr">82%</b><small>{{ __('landing.hero.card_occupancy') }}</small></div>
                    <div class="mock-stat"><b class="ltr">4.8 ★</b><small>{{ __('landing.hero.card_rating') }}</small></div>
                </div>
                <div class="confirm">
                    {!! $icon('check-badge') !!}
                    <div>{{ __('landing.hero.card_confirmed') }}<small>{{ __('landing.hero.card_confirmed_detail') }}</small></div>
                </div>
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
                            <dd class="grad-text ltr">{{ number_format($value) }}</dd>
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

    <section class="block" id="app" aria-labelledby="app-title">
        <div class="wrap">
            <div class="app">
                <div>
                    <h2 id="app-title">{{ __('landing.app.title') }}</h2>
                    <p>{{ __('landing.app.subtitle') }}</p>
                    <ul>
                        @foreach (['point_1', 'point_2', 'point_3'] as $point)
                            <li>{!! $icon('sparkles') !!}{{ __('landing.app.'.$point) }}</li>
                        @endforeach
                    </ul>
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
                <div class="phone" aria-hidden="true">
                    <div class="phone-bar short"></div>
                    @foreach (__('landing.phone_cards') as $label)
                        <div class="phone-card"><span>{{ $label }}</span></div>
                    @endforeach
                    <div class="phone-bar"></div>
                    <div class="phone-bar short"></div>
                </div>
            </div>
        </div>
    </section>

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
@endsection
