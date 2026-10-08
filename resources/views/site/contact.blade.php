@extends('site.layout')

@php
    $phoneHref = $supportPhone ? preg_replace('/[^\d+]/', '', (string) $supportPhone) : null;
    $channels = array_filter([
        $phoneHref ? ['icon' => 'phone', 'label' => __('site.contact.phone'), 'hint' => __('site.contact.phone_hint'), 'value' => $supportPhone, 'href' => 'tel:'.$phoneHref, 'ltr' => true] : null,
        $supportEmail ? ['icon' => 'envelope', 'label' => __('site.contact.email'), 'hint' => __('site.contact.email_hint'), 'value' => $supportEmail, 'href' => 'mailto:'.$supportEmail, 'ltr' => true] : null,
        $phoneHref ? ['icon' => 'chat-bubble-left-right', 'label' => __('site.contact.whatsapp'), 'hint' => __('site.contact.whatsapp_hint'), 'value' => $supportPhone, 'href' => 'https://wa.me/'.ltrim($phoneHref, '+'), 'ltr' => true, 'external' => true] : null,
    ]);
    $icon = fn (string $name) => svg('heroicon-o-'.$name, 'ico')->toHtml();
@endphp

@section('title', __('site.contact.meta_title'))
@section('description', __('site.contact.meta_description'))

@section('styles')
.channels { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr)); gap: 1rem; margin: 0 0 1.5rem; padding: 0; list-style: none; }
.channel { position: relative; display: flex; flex-direction: column; gap: .2rem; height: 100%; padding: 1.6rem; border-radius: var(--radius); border: 1px solid var(--line); background: var(--card); overflow: hidden; transition: border-color .2s, transform .2s; }
.channel::before { content: ""; position: absolute; inset-inline: 0; top: 0; height: 3px; background: var(--grad); opacity: .6; }
.channel:hover { border-color: rgba(230,0,126,.45); transform: translateY(-2px); }
.channel-icon { width: 3rem; height: 3rem; border-radius: 1rem; display: grid; place-items: center; background: var(--grad); color: #fff; margin-bottom: .9rem; }
.channel h2 { margin: 0; font-size: 1.1rem; }
.channel .hint { color: var(--muted); font-size: .9rem; }
.channel .value { margin-top: .7rem; font-weight: 600; font-size: 1.05rem; overflow-wrap: anywhere; }
.info { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 20rem), 1fr)); gap: 1rem; }
.info-card { padding: 1.6rem; border-radius: var(--radius); border: 1px solid var(--line); background: var(--bg-2); }
.info-card h2 { font-size: 1.1rem; margin: 0 0 .4rem; }
.info-card p { color: var(--muted); margin: 0 0 1.1rem; }
.info-card .cta-row { display: flex; flex-wrap: wrap; gap: .6rem; }
.info-card.accent { background: linear-gradient(150deg, rgba(249,178,51,.12), rgba(230,0,126,.16) 50%, rgba(123,47,247,.14)); }
.none { text-align: center; color: var(--muted); }
@endsection

@section('content')
    <section class="page-hero">
        <div class="wrap">
            <span class="badge"><span class="badge-dot" aria-hidden="true"></span>{{ __('site.contact.badge') }}</span>
            <h1>{{ __('site.contact.title') }}</h1>
            <p>{{ __('site.contact.subtitle') }}</p>
        </div>
    </section>

    <div class="wrap">
        @if ($channels)
            <ul class="channels">
                @foreach ($channels as $channel)
                    <li>
                        <a class="channel" href="{{ $channel['href'] }}" @if (! empty($channel['external'])) target="_blank" rel="noopener" @endif>
                            <span class="channel-icon">{!! $icon($channel['icon']) !!}</span>
                            <h2>{{ $channel['label'] }}</h2>
                            <span class="hint">{{ $channel['hint'] }}</span>
                            <span class="value ltr">{{ $channel['value'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="none">{{ __('site.contact.none') }}</p>
        @endif

        <div class="info">
            <div class="info-card accent">
                <h2>{{ __('site.contact.providers_title') }}</h2>
                <p>{{ __('site.contact.providers_body') }}</p>
                <div class="cta-row">
                    <a class="btn btn-primary btn-sm" href="{{ url('/provider/login') }}">{{ __('landing.sign_in') }}</a>
                    <a class="btn btn-ghost btn-sm" href="{{ url('/provider/register') }}">{{ __('landing.join') }}</a>
                </div>
            </div>
            <div class="info-card">
                <h2>{{ __('site.contact.faq_title') }}</h2>
                <p>{{ __('site.contact.faq_body') }}</p>
                <a class="btn btn-ghost btn-sm" href="{{ url('/') }}/#faq">{{ __('site.contact.faq_link') }}</a>
            </div>
        </div>
    </div>
@endsection
