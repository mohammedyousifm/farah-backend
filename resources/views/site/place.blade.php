@extends('site.layout')

@php
    $name = $venue->tr('name');
    $city = $venue->city?->tr('name');
    $summary = trim((string) ($venue->tr('summary') ?: strip_tags((string) $venue->tr('description'))));
    $cover = $venue->coverUrl();
    $days = __('site.place.days');
    $hours = $venue->hours->sortBy(fn ($h) => ($h->weekday + 1) % 7); // Saturday first
    $mapsUrl = $venue->lat !== null && $venue->lng !== null
        ? 'https://www.google.com/maps/search/?api=1&query='.$venue->lat.','.$venue->lng
        : null;
    $currency = __('site.place.currency');
    $place = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'SportsActivityLocation',
        'name' => $name,
        'description' => $summary ?: null,
        'image' => $cover,
        'url' => url()->current(),
        'telephone' => $venue->phone,
        'priceRange' => $venue->min_price ? $venue->min_price.' SAR+' : null,
        'address' => array_filter(['@type' => 'PostalAddress', 'streetAddress' => $venue->address, 'addressLocality' => $city, 'addressCountry' => 'SA']),
        'geo' => $venue->lat !== null ? ['@type' => 'GeoCoordinates', 'latitude' => $venue->lat, 'longitude' => $venue->lng] : null,
        'aggregateRating' => $venue->rating_count > 0 ? ['@type' => 'AggregateRating', 'ratingValue' => round((float) $venue->rating_avg, 1), 'reviewCount' => $venue->rating_count] : null,
        'openingHoursSpecification' => $hours->filter->isOpen()->map(fn ($h) => [
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$h->weekday],
            'opens' => $h->opens(),
            'closes' => $h->closes(),
        ])->values()->all() ?: null,
    ]);
@endphp

@section('title', $name.($city ? ' - '.$city : '').' | '.__('site.brand'))
@section('description', \Illuminate\Support\Str::limit($summary ?: __('site.place.fallback_description', ['name' => $name, 'city' => $city]), 155))

@push('head')
    @if ($cover)
        <meta property="og:image" content="{{ $cover }}">
    @endif
    <script type="application/ld+json">{!! json_encode($place, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endpush

@section('styles')
.crumbs { display: flex; justify-content: center; gap: .5rem; flex-wrap: wrap; margin: 0; padding: 0; list-style: none; font-size: .85rem; color: var(--muted); }
.crumbs li + li::before { content: "/"; margin-inline-end: .5rem; opacity: .6; }
.place-cover { width: 100%; aspect-ratio: 16 / 7; object-fit: cover; border-radius: var(--radius); border: 1px solid var(--line); display: block; }
.place-grid { display: grid; grid-template-columns: minmax(0, 1fr) 19rem; gap: 2rem; align-items: start; margin-top: 2rem; }
.place-card { padding: 1.5rem; border-radius: var(--radius); border: 1px solid var(--line); background: var(--card); }
.place-card + .place-card { margin-top: 1.2rem; }
.place-card h2 { font-size: 1.1rem; margin: 0 0 .9rem; }
.place-card p { color: #D6D6DC; line-height: 1.9; margin: 0; }
.place-meta { display: flex; flex-wrap: wrap; justify-content: center; gap: .6rem 1.2rem; color: var(--muted); font-size: .92rem; }
.place-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .55rem; }
.place-list li { display: flex; justify-content: space-between; gap: 1rem; color: #D6D6DC; }
.place-list li span:last-child { color: var(--muted); white-space: nowrap; }
.place-cta { display: grid; gap: .7rem; }
@media (max-width: 900px) { .place-grid { grid-template-columns: minmax(0, 1fr); } }
@endsection

@section('content')
    <section class="page-hero">
        <div class="wrap">
            <nav aria-label="{{ __('site.a11y.breadcrumb') }}">
                <ol class="crumbs">
                    <li><a href="{{ url('/') }}">{{ __('site.nav.home') }}</a></li>
                    @if ($city)<li>{{ $city }}</li>@endif
                    <li aria-current="page">{{ $name }}</li>
                </ol>
            </nav>
            <h1>{{ $name }}</h1>
            <p class="place-meta">
                @if ($city)<span>{{ $city }}</span>@endif
                @if ($venue->rating_count > 0)<span>★ {{ number_format((float) $venue->rating_avg, 1) }} ({{ $venue->rating_count }})</span>@endif
                @if ($venue->min_price)<span>{{ __('site.place.from_price', ['price' => (float) $venue->min_price, 'currency' => $currency]) }}</span>@endif
            </p>
        </div>
    </section>

    <div class="wrap">
        @if ($cover)
            <img class="place-cover" src="{{ $cover }}" alt="{{ $name }}" width="1200" height="525" decoding="async">
        @endif

        <div class="place-grid">
            <div>
                @if ($summary !== '')
                    <section class="place-card">
                        <h2>{{ __('site.place.about') }}</h2>
                        <p>{{ $summary }}</p>
                    </section>
                @endif
                @if ($venue->activities->isNotEmpty())
                    <section class="place-card">
                        <h2>{{ __('site.place.activities') }}</h2>
                        <ul class="place-list">
                            @foreach ($venue->activities as $activity)
                                <li><span>{{ $activity->tr('name') }}</span><span>{{ __('site.place.per_hour', ['price' => (float) $activity->price_per_hour, 'currency' => $currency]) }}</span></li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>

            <aside>
                @if ($hours->isNotEmpty())
                    <section class="place-card">
                        <h2>{{ __('site.place.hours') }}</h2>
                        <ul class="place-list">
                            @foreach ($hours as $h)
                                <li><span>{{ $days[$h->weekday] }}</span><span dir="ltr">{{ $h->isOpen() ? $h->opens().' - '.$h->closes() : __('site.place.closed') }}</span></li>
                            @endforeach
                        </ul>
                    </section>
                @endif
                <section class="place-card place-cta">
                    <h2>{{ __('site.place.book_title') }}</h2>
                    <p>{{ __('site.place.book_body') }}</p>
                    <a class="btn btn-primary" href="{{ url('/').'#app' }}">{{ __('site.place.get_app') }}</a>
                    @if ($mapsUrl)
                        <a class="btn btn-ghost" href="{{ $mapsUrl }}" target="_blank" rel="noopener">{{ __('site.place.directions') }}</a>
                    @endif
                    @if ($venue->address)
                        <p>{{ $venue->address }}</p>
                    @endif
                </section>
            </aside>
        </div>
    </div>
@endsection
