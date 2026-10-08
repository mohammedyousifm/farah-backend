@php
    $locale = app()->getLocale();
    $otherLang = $locale === 'ar' ? 'en' : 'ar';
    $onHome = request()->routeIs('landing');
    $home = url('/');
    $anchor = fn (string $id) => ($onHome ? '' : $home.'/').'#'.$id;
    $loginUrl = url('/provider/login');
    $registerUrl = url('/provider/register');
    $switchUrl = request()->fullUrlWithQuery(['lang' => $otherLang]);
    $canonical = request()->path() === '/' ? $home.'/' : url()->current();
    // @section('x', 'value') stores escaped text; decode once so {{ }} below escapes it exactly once.
    $title = html_entity_decode(trim($__env->yieldContent('title')), ENT_QUOTES | ENT_HTML5) ?: __('landing.meta_title');
    $description = html_entity_decode(trim($__env->yieldContent('description')), ENT_QUOTES | ENT_HTML5) ?: __('landing.meta_description');
    $shareImage = asset('images/og-share.png');
    $phoneHref = $supportPhone ? preg_replace('/[^\d+]/', '', (string) $supportPhone) : null;
    $whatsApp = $phoneHref ? 'https://wa.me/'.ltrim($phoneHref, '+') : null;
    $navItems = [
        ['href' => $anchor('how'), 'label' => __('landing.nav.how')],
        ['href' => $anchor('features'), 'label' => __('landing.nav.features')],
        ['href' => $anchor('sectors'), 'label' => __('landing.nav.sectors')],
        ['href' => $anchor('app'), 'label' => __('landing.nav.app')],
        ['href' => $anchor('faq'), 'label' => __('landing.nav.faq')],
        ['href' => route('site.contact'), 'label' => __('site.nav.contact'), 'current' => request()->routeIs('site.contact')],
    ];
    $organization = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => __('site.brand'),
        'alternateName' => $locale === 'ar' ? 'Farah' : 'فرة',
        'url' => $home,
        'logo' => asset('images/logo-mark.png'),
        'image' => $shareImage,
        'description' => __('landing.meta_description'),
        'contactPoint' => ($supportPhone || $supportEmail) ? [array_filter([
            '@type' => 'ContactPoint',
            'contactType' => 'customer support',
            'telephone' => $phoneHref,
            'email' => $supportEmail,
            'areaServed' => 'SA',
            'availableLanguage' => ['Arabic', 'English'],
        ])] : null,
    ]);
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}?lang={{ $locale }}">
    <link rel="alternate" hreflang="ar" href="{{ $canonical }}?lang=ar">
    <link rel="alternate" hreflang="en" href="{{ $canonical }}?lang=en">
    <link rel="alternate" hreflang="x-default" href="{{ $canonical }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ __('site.brand') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}?lang={{ $locale }}">
    <meta property="og:locale" content="{{ $locale === 'ar' ? 'ar_SA' : 'en_US' }}">
    <meta property="og:locale:alternate" content="{{ $locale === 'ar' ? 'en_US' : 'ar_SA' }}">
    <meta property="og:image" content="{{ $shareImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ __('site.og_image_alt') }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $shareImage }}">
    <meta name="theme-color" content="#0B0B10">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&amp;display=swap" rel="stylesheet">
    <script type="application/ld+json">{!! json_encode($organization, $jsonFlags) !!}</script>
    @stack('head')
    <style>
@include('site.partials.base-css')
@yield('styles')
    </style>
</head>
<body>
    <a class="skip" href="#main">{{ __('site.a11y.skip') }}</a>

    <header class="top">
        <div class="wrap top-inner">
            <a href="{{ $home }}" class="brand" aria-label="{{ __('site.a11y.home') }}">
                <img src="{{ asset('images/logo-mark-sm.png') }}" alt="" width="27" height="36">
                <span class="brand-text" aria-hidden="true"><span class="brand-ar">فرة</span><span class="brand-en">FARAH</span></span>
            </a>
            <nav class="links" aria-label="{{ __('site.a11y.main_nav') }}">
                @foreach ($navItems as $item)
                    <a href="{{ $item['href'] }}" @if (! empty($item['current'])) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>
            <div class="top-actions">
                <a class="lang" href="{{ $switchUrl }}" hreflang="{{ $otherLang }}" lang="{{ $otherLang }}" aria-label="{{ __('site.a11y.switch_lang') }}">{{ __('landing.switch_lang') }}</a>
                <a class="btn btn-ghost btn-sm" href="{{ $loginUrl }}">{{ __('landing.sign_in') }}</a>
                <a class="btn btn-primary btn-sm" href="{{ $registerUrl }}">{{ __('landing.join') }}</a>
                <button type="button" class="menu-btn" id="menu-btn" aria-expanded="false" aria-controls="mnav"
                        aria-label="{{ __('site.a11y.menu_open') }}" data-open="{{ __('site.a11y.menu_open') }}" data-close="{{ __('site.a11y.menu_close') }}">
                    <svg class="i-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    <svg class="i-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
        </div>
        <div class="mnav" id="mnav" hidden>
            <nav class="wrap" aria-label="{{ __('site.a11y.menu') }}">
                <ul>
                    @foreach ($navItems as $item)
                        <li><a href="{{ $item['href'] }}" @if (! empty($item['current'])) aria-current="page" @endif>{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
                <div class="mnav-actions">
                    <a class="btn btn-primary" href="{{ $registerUrl }}">{{ __('landing.join') }}</a>
                    <a class="btn btn-ghost" href="{{ $loginUrl }}">{{ __('landing.sign_in') }}</a>
                </div>
            </nav>
        </div>
    </header>

    <main id="main" tabindex="-1">
        @yield('content')
    </main>

    <footer class="site">
        <div class="wrap">
            <div class="foot-grid">
                <div>
                    <a href="{{ $home }}" class="brand" aria-label="{{ __('site.a11y.home') }}">
                        <img src="{{ asset('images/logo-mark-sm.png') }}" alt="" width="27" height="36" loading="lazy" decoding="async">
                        <span class="brand-text" aria-hidden="true"><span class="brand-ar">فرة</span><span class="brand-en">FARAH</span></span>
                    </a>
                    <p>{{ __('landing.footer.tagline') }}</p>
                </div>
                <nav aria-labelledby="foot-providers">
                    <h2 id="foot-providers">{{ __('landing.footer.providers') }}</h2>
                    <ul>
                        <li><a href="{{ $registerUrl }}">{{ __('landing.join') }}</a></li>
                        <li><a href="{{ $loginUrl }}">{{ __('landing.sign_in') }}</a></li>
                        <li><a href="{{ $anchor('how') }}">{{ __('landing.nav.how') }}</a></li>
                        <li><a href="{{ $anchor('faq') }}">{{ __('landing.nav.faq') }}</a></li>
                    </ul>
                </nav>
                <nav aria-labelledby="foot-company">
                    <h2 id="foot-company">{{ __('site.footer.company') }}</h2>
                    <ul>
                        @foreach ($footerPages as $slug => $footerPage)
                            <li><a href="{{ route('site.page', $slug) }}">{{ $footerPage->tr('title') }}</a></li>
                        @endforeach
                        <li><a href="{{ route('site.contact') }}">{{ __('site.nav.contact') }}</a></li>
                    </ul>
                </nav>
                <div>
                    <h2>{{ __('landing.footer.contact') }}</h2>
                    <ul>
                        @if ($phoneHref)
                            <li><a class="ltr" href="tel:{{ $phoneHref }}">{{ $supportPhone }}</a></li>
                            <li><a href="{{ $whatsApp }}" rel="noopener" target="_blank">{{ __('site.footer.whatsapp') }}</a></li>
                        @endif
                        @if ($supportEmail)
                            <li><a class="ltr" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></li>
                        @endif
                        <li><a href="{{ $switchUrl }}" hreflang="{{ $otherLang }}" lang="{{ $otherLang }}">{{ __('landing.switch_lang') }}</a></li>
                    </ul>
                </div>
            </div>
            <div class="copy">
                <span>© {{ date('Y') }} {{ __('site.brand') }}. {{ __('landing.footer.rights') }}</span>
                <span class="ltr">farah.sa</span>
            </div>
        </div>
    </footer>

    <script>
        (function () {
            var btn = document.getElementById('menu-btn'), menu = document.getElementById('mnav');
            if (!btn || !menu) return;
            function toggle(open, focusButton) {
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                btn.setAttribute('aria-label', open ? btn.dataset.close : btn.dataset.open);
                menu.hidden = !open;
                if (open) { var first = menu.querySelector('a'); if (first) first.focus(); }
                else if (focusButton) { btn.focus(); }
            }
            btn.addEventListener('click', function () { toggle(btn.getAttribute('aria-expanded') !== 'true', false); });
            menu.addEventListener('click', function (e) { if (e.target.closest('a')) toggle(false, false); });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && btn.getAttribute('aria-expanded') === 'true') toggle(false, true);
            });
            var wide = window.matchMedia('(min-width: 1025px)');
            var onWide = function (e) { if (e.matches) toggle(false, false); };
            wide.addEventListener ? wide.addEventListener('change', onWide) : wide.addListener(onWide);
        })();
    </script>
</body>
</html>
