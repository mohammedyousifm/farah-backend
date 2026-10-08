@php
    // Error pages must always render: no database queries here, locale read defensively.
    $locale = \App\Http\Controllers\Web\Support\SiteLocale::forErrorPage();
    $otherLang = $locale === 'ar' ? 'en' : 'ar';
    // Also the layout for Laravel's built-in 401/402/403/419/429 views (they extend errors::minimal).
    $code = trim($__env->yieldContent('code')) ?: '500';
    $key = 'site.errors.'.$code;
    $known = \Illuminate\Support\Facades\Lang::has($key.'.title');
    $title = $known ? __($key.'.title') : html_entity_decode(trim($__env->yieldContent('title')), ENT_QUOTES | ENT_HTML5);
    $message = $known ? __($key.'.body') : html_entity_decode(trim($__env->yieldContent('message')), ENT_QUOTES | ENT_HTML5);
    // A deliberate 403 reason (e.g. "account suspended") is more useful than the generic text.
    if ($code === '403' && isset($exception) && ! in_array($exception->getMessage(), ['', 'Forbidden', 'This action is unauthorized.'], true)) {
        $message = $exception->getMessage();
    }
    // Buttons are built here (not in the child views) so they use the locale resolved above.
    $showContact = ! in_array($code, ['503', '429'], true);
    try {
        $switchUrl = request()->fullUrlWithQuery(['lang' => $otherLang]);
    } catch (\Throwable $e) {
        $switchUrl = url('/').'?lang='.$otherLang;
    }
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} | {{ __('site.brand') }}</title>
    <meta name="theme-color" content="#0B0B10">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&amp;display=swap" rel="stylesheet">
    <style>
@include('site.partials.base-css')
body { display: flex; flex-direction: column; min-height: 100vh; min-height: 100dvh; }
main { flex: 1; display: grid; place-items: center; }
.err { position: relative; overflow: hidden; width: 100%; padding: 4rem 0 5rem; text-align: center; }
.err::before { content: ""; position: absolute; inset: 0; pointer-events: none;
    background: radial-gradient(30rem 22rem at 20% 20%, rgba(249,178,51,.14), transparent 60%), radial-gradient(32rem 24rem at 80% 30%, rgba(230,0,126,.18), transparent 60%), radial-gradient(34rem 24rem at 50% 110%, rgba(61,139,255,.16), transparent 60%); }
.err > * { position: relative; }
.err-code { display: block; font-size: clamp(4.5rem, 18vw, 8.5rem); font-weight: 700; line-height: 1; letter-spacing: .02em; margin: 0 0 .5rem; direction: ltr; }
.err h1 { font-size: clamp(1.6rem, 4vw, 2.3rem); line-height: 1.3; margin: 0 0 .7rem; }
.err p { color: var(--muted); max-width: 32rem; margin: 0 auto 2rem; font-size: 1.05rem; }
.err .cta-row { display: flex; flex-wrap: wrap; justify-content: center; gap: .75rem; }
.err-foot { padding: 1.5rem 0; border-top: 1px solid var(--line); color: var(--muted); font-size: .82rem; text-align: center; }
    </style>
</head>
<body>
    <header class="top">
        <div class="wrap top-inner">
            <a href="{{ url('/') }}" class="brand" aria-label="{{ __('site.a11y.home') }}">
                <img src="{{ asset('images/logo-mark-sm.png') }}" alt="" width="27" height="36">
                <span class="brand-text" aria-hidden="true"><span class="brand-ar">فرة</span><span class="brand-en">FARAH</span></span>
            </a>
            <div class="top-actions">
                <a class="lang" href="{{ $switchUrl }}" hreflang="{{ $otherLang }}" lang="{{ $otherLang }}" aria-label="{{ __('site.a11y.switch_lang') }}">{{ __('landing.switch_lang') }}</a>
            </div>
        </div>
    </header>

    <main id="main">
        <section class="err">
            <div class="wrap">
                <span class="err-code grad-text" aria-hidden="true">{{ $code }}</span>
                <h1>{{ $title }}</h1>
                @if ($message !== '' && $message !== $title)
                    <p>{{ $message }}</p>
                @endif
                <div class="cta-row">
                    @if ($code === '503')
                        <a class="btn btn-primary" href="{{ request()->fullUrlWithoutQuery(['lang']) }}">{{ __('site.errors.retry') }}</a>
                    @else
                        <a class="btn btn-primary" href="{{ url('/') }}">{{ __('site.errors.home') }}</a>
                    @endif
                    @if ($showContact)
                        <a class="btn btn-ghost" href="{{ url('/contact') }}">{{ __('site.errors.contact') }}</a>
                    @endif
                </div>
            </div>
        </section>
    </main>

    <footer class="err-foot">
        <div class="wrap">© {{ date('Y') }} {{ __('site.brand') }}. {{ __('landing.footer.rights') }}</div>
    </footer>
</body>
</html>
