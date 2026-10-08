@extends('site.layout')

@php
    $pageTitle = $page->tr('title');
    $excerpt = trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace('<', ' <', $body))));
@endphp

@section('title', $pageTitle.' | '.__('site.brand'))
@section('description', $excerpt !== '' ? \Illuminate\Support\Str::limit($excerpt, 155) : __('landing.meta_description'))

@section('styles')
.crumbs { display: flex; justify-content: center; gap: .5rem; flex-wrap: wrap; margin: 0; padding: 0; list-style: none; font-size: .85rem; color: var(--muted); }
.crumbs li + li::before { content: "/"; margin-inline-end: .5rem; opacity: .6; }
.crumbs a:hover { color: var(--text); }
.page-meta { font-size: .88rem; }
.page-grid { display: grid; grid-template-columns: minmax(0, 1fr) 17rem; gap: 2.5rem; align-items: start; }
.prose { padding: 2.2rem; border-radius: var(--radius); border: 1px solid var(--line); background: var(--card); font-size: 1.02rem; line-height: 1.9; overflow-wrap: anywhere; }
.prose > :first-child { margin-top: 0; }
.prose > :last-child { margin-bottom: 0; }
.prose h2, .prose h3, .prose h4 { line-height: 1.4; margin: 2rem 0 .7rem; }
.prose h2 { font-size: 1.4rem; }
.prose h3 { font-size: 1.18rem; }
.prose p, .prose ul, .prose ol, .prose blockquote, .prose table { margin: 0 0 1.1rem; }
.prose p, .prose li { color: #D6D6DC; }
.prose ul, .prose ol { padding-inline-start: 1.4rem; }
.prose li { margin-bottom: .35rem; }
.prose a { color: var(--orange); text-decoration: underline; text-underline-offset: 3px; }
.prose blockquote { padding-inline-start: 1rem; border-inline-start: 3px solid var(--magenta); color: var(--muted); }
.prose table { width: 100%; border-collapse: collapse; display: block; overflow-x: auto; }
.prose th, .prose td { border: 1px solid var(--line); padding: .5rem .7rem; text-align: start; }
.prose hr { border: 0; height: 1px; background: var(--line); margin: 2rem 0; }
.prose .empty { color: var(--muted); }
aside.side { display: grid; gap: 1rem; position: sticky; top: 5.5rem; }
.side-card { padding: 1.3rem; border-radius: var(--radius); border: 1px solid var(--line); background: var(--bg-2); }
.side-card h2 { font-size: 1rem; margin: 0 0 .7rem; }
.side-card ul { list-style: none; margin: 0; padding: 0; display: grid; gap: .2rem; }
.side-card li a { display: block; padding: .4rem .6rem; border-radius: .6rem; color: var(--muted); }
.side-card li a:hover { background: rgba(255,255,255,.06); color: var(--text); }
.side-card li a[aria-current="page"] { color: var(--text); background: rgba(230,0,126,.14); }
.side-card p { color: var(--muted); margin: 0 0 1rem; font-size: .93rem; }
.side-card.help { background: linear-gradient(150deg, rgba(230,0,126,.16), rgba(123,47,247,.14) 55%, rgba(61,139,255,.1)); }
@media (max-width: 900px) {
    .page-grid { grid-template-columns: minmax(0, 1fr); }
    aside.side { position: static; }
}
@media (max-width: 600px) { .prose { padding: 1.4rem 1.1rem; } }
@endsection

@section('content')
    <section class="page-hero">
        <div class="wrap">
            <nav aria-label="{{ __('site.a11y.breadcrumb') }}">
                <ol class="crumbs">
                    <li><a href="{{ url('/') }}">{{ __('site.nav.home') }}</a></li>
                    <li aria-current="page">{{ $pageTitle }}</li>
                </ol>
            </nav>
            <h1>{{ $pageTitle }}</h1>
            @if ($page->updated_at)
                <p class="page-meta">{{ __('site.page.updated', ['date' => $page->updated_at->translatedFormat('j F Y')]) }}</p>
            @endif
        </div>
    </section>

    <div class="wrap page-grid">
        <article class="prose" lang="{{ app()->getLocale() }}">
            @if ($body !== '')
                {!! $body !!}
            @else
                <p class="empty">{{ __('site.page.empty') }}</p>
            @endif
        </article>

        <aside class="side">
            @if (count($footerPages) > 1)
                <nav class="side-card" aria-labelledby="other-pages">
                    <h2 id="other-pages">{{ __('site.page.other_pages') }}</h2>
                    <ul>
                        @foreach ($footerPages as $slug => $other)
                            <li><a href="{{ route('site.page', $slug) }}" @if ($slug === $page->slug) aria-current="page" @endif>{{ $other->tr('title') }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
            <div class="side-card help">
                <h2>{{ __('site.page.help_title') }}</h2>
                <p>{{ __('site.page.help_body') }}</p>
                <a class="btn btn-ghost btn-sm" href="{{ route('site.contact') }}">{{ __('site.nav.contact') }}</a>
            </div>
        </aside>
    </div>
@endsection
