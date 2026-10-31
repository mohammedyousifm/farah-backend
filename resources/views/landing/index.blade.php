@extends('site.layout')

@php
    $registerUrl = url('/provider/register');
    $phoneHref = $supportPhone ? preg_replace('/[^\d+]/', '', (string) $supportPhone) : null;

    // The app tour: real dark-mode screens, each tied to the flow step it belongs to.
    $tour = [
        ['welcome-1', 0], ['welcome-2', 0], ['welcome-3', 0],
        ['sign-in', 1], ['verify', 1],
        ['home', 2], ['explore', 2], ['venue', 2], ['activities', 2],
        ['choose-time', 3],
        ['payment', 4], ['paid', 4],
        ['booking-qr', 5],
    ];
@endphp

@section('styles')
.hero { min-height: min(calc(100svh - 4.25rem), 52rem); display: flex; align-items: center; padding: 2rem 0; }
.hero-grid { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 5rem; align-items: center; width: min(1040px, 100% - 2.5rem); }
.hero h1 { font-size: clamp(2.2rem, 4.6vw, 3.6rem); line-height: 1.2; font-weight: 700; margin: 0 0 1rem; }
.hero .lead { font-size: 1.1rem; color: var(--muted); max-width: 30rem; margin: 0 0 2rem; }
.flow { list-style: none; margin: 0 0 2rem; padding: 0; display: grid; gap: .1rem; max-width: 24rem; }
.flow button { width: 100%; display: flex; align-items: center; gap: .85rem; padding: .5rem 0; border: 0; background: none; color: var(--muted); font: inherit; text-align: start; cursor: pointer; transition: color .25s; }
.flow button:hover, .flow .is-on button { color: var(--text); }
.flow i { width: 1.6rem; font-style: normal; font-size: .85rem; color: var(--muted); }
.flow .is-on i { color: var(--accent); }
.hero-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1.25rem; }
.soon { color: var(--muted); font-size: .9rem; }

.phone { position: relative; height: min(calc(100svh - 8rem), 42rem); aspect-ratio: 9 / 20; border-radius: 2.6rem; border: 6px solid #26262c; background: #000; overflow: hidden; }
.phone img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: 0; transition: opacity .5s ease; }
.phone img.is-on { opacity: 1; }

section.block { padding: 6rem 0; border-top: 1px solid var(--line); }
.narrow { width: min(1040px, 100% - 2.5rem); }
.block h2 { font-size: clamp(1.7rem, 3.2vw, 2.4rem); line-height: 1.3; margin: 0 0 .75rem; }
.block .intro { color: var(--muted); font-size: 1.08rem; max-width: 40rem; margin: 0 0 3rem; }

.sides { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 3rem; }
.sides h3 { font-size: 1.1rem; margin: 0 0 .75rem; }
.sides ul { list-style: none; margin: 0; padding: 0; color: var(--muted); }
.sides li { padding: .7rem 0; border-top: 1px solid var(--line); }

.plans { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 15rem), 1fr)); gap: 2.5rem; }
.plan { display: flex; flex-direction: column; }
.plan h3 { font-size: 1.05rem; font-weight: 600; margin: 0 0 .5rem; color: var(--muted); }
.plan-price { font-size: 2rem; font-weight: 700; line-height: 1.2; margin-bottom: 1rem; }
.plan-price small { font-size: .85rem; font-weight: 400; color: var(--muted); }
.plan > p { margin: 0 0 1rem; color: var(--muted); font-size: .95rem; }
.plan ul { list-style: none; margin: 0 0 1.5rem; padding: 0; font-size: .95rem; }
.plan li { padding: .6rem 0; border-top: 1px solid var(--line); }
.plan li small { color: var(--muted); }
.plan .btn { margin-top: auto; }
.plans-note { color: var(--muted); font-size: .9rem; margin: 2.5rem 0 0; max-width: 40rem; }

.contact { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 2rem; align-items: end; }
.contact .intro { margin-bottom: 0; }
.contact ul { list-style: none; margin: 1.5rem 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: .5rem 2rem; }
.contact li a:hover { color: var(--accent); }

@media (max-width: 860px) {
    .hero { min-height: 0; padding: 3rem 0; }
    .hero-grid { display: flex; flex-direction: column; align-items: stretch; gap: 0; }
    .hero-grid > div:first-child { display: contents; }
    .phone { order: 1; height: auto; width: min(17rem, 100%); margin: 0 auto 2rem; }
    .flow { order: 2; max-width: none; }
    .hero-actions { order: 3; }
    .sides, .contact { grid-template-columns: minmax(0, 1fr); }
    section.block { padding: 4rem 0; }
}
@endsection

@section('content')
    <section class="hero" aria-labelledby="hero-title">
        <div class="wrap hero-grid">
            <div>
                <h1 id="hero-title">{{ __('landing.hero.title') }}</h1>
                <p class="lead">{{ __('landing.hero.subtitle') }}</p>
                <ol class="flow" id="app-flow">
                    @foreach (__('landing.hero.flow') as $i => $step)
                        <li @class(['is-on' => $i === 0])>
                            <button type="button" data-step="{{ $i }}"><i class="ltr">{{ $i + 1 }}</i>{{ $step }}</button>
                        </li>
                    @endforeach
                </ol>
                <div class="hero-actions">
                    <a class="btn btn-primary" href="{{ $registerUrl }}">{{ __('landing.join') }}</a>
                    {{-- Not links until the app is published. --}}
                    <span class="soon">{{ __('landing.hero.stores') }}</span>
                </div>
            </div>
            <div class="phone" id="app-tour" role="img" aria-label="{{ __('landing.hero.tour_alt') }}">
                @foreach ($tour as [$frame, $step])
                    <img src="{{ asset('images/app-tour/'.$frame.'.webp') }}" alt="" width="540" height="1200" data-step="{{ $step }}" @class(['is-on' => $loop->first])>
                @endforeach
            </div>
        </div>
    </section>

    <section class="block" id="about" aria-labelledby="about-title">
        <div class="wrap narrow">
            <h2 id="about-title">{{ __('landing.about.title') }}</h2>
            <p class="intro">{{ __('landing.about.body') }}</p>
            <div class="sides">
                @foreach (['customers', 'owners'] as $side)
                    <div>
                        <h3>{{ __('landing.about.'.$side.'_title') }}</h3>
                        <ul>
                            @foreach (__('landing.about.'.$side) as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Plans for store owners: the choice is made here or on the sign-up form, before the account exists. --}}
    @if ($plans->isNotEmpty())
        <section class="block" id="plans" aria-labelledby="plans-title">
            <div class="wrap narrow">
                <h2 id="plans-title">{{ __('landing.plans.title') }}</h2>
                <p class="intro">{{ __('landing.plans.subtitle') }}</p>
                <div class="plans">
                    @foreach ($plans as $plan)
                        <div class="plan">
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
                                        {{ $item->feature->getLabel() }}@if ($item->value) ({{ __('landing.plans.up_to', ['count' => $item->value]) }})@endif
                                        @if (! $plan->is_free && $item->days && $item->days < $plan->duration_days)
                                            <small>· {{ __('landing.plans.days', ['days' => $item->days]) }}</small>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            <a class="btn btn-ghost" href="{{ $registerUrl }}?plan={{ $plan->id }}">
                                {{ $plan->is_free ? __('landing.plans.start_free') : __('landing.plans.choose') }}
                            </a>
                        </div>
                    @endforeach
                </div>
                <p class="plans-note">{{ __('landing.plans.note') }}</p>
            </div>
        </section>
    @endif

    <section class="block" id="contact" aria-labelledby="contact-title">
        <div class="wrap narrow contact">
            <div>
                <h2 id="contact-title">{{ __('landing.contact.title') }}</h2>
                <p class="intro">{{ __('landing.contact.body') }}</p>
                @if ($phoneHref || $supportEmail)
                    <ul>
                        @if ($phoneHref)
                            <li><a class="ltr" href="tel:{{ $phoneHref }}">{{ $supportPhone }}</a></li>
                            <li><a href="https://wa.me/{{ ltrim($phoneHref, '+') }}" rel="noopener" target="_blank">{{ __('site.footer.whatsapp') }}</a></li>
                        @endif
                        @if ($supportEmail)
                            <li><a class="ltr" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></li>
                        @endif
                    </ul>
                @endif
            </div>
            <a class="btn btn-primary" href="{{ route('site.contact') }}">{{ __('landing.contact.cta') }}</a>
        </div>
    </section>

    {{-- Plays the app tour and keeps the numbered flow in step with the screen. --}}
    <script>
        (() => {
            const frames = [...document.querySelectorAll('#app-tour img')];
            const steps = [...document.querySelectorAll('#app-flow li')];
            let current = 0, timer = null;
            const show = i => {
                frames[current].classList.remove('is-on');
                current = i;
                frames[current].classList.add('is-on');
                steps.forEach((step, n) => step.classList.toggle('is-on', n === +frames[current].dataset.step));
            };
            const play = () => {
                clearInterval(timer);
                timer = matchMedia('(prefers-reduced-motion: reduce)').matches
                    ? null
                    : setInterval(() => show((current + 1) % frames.length), 2600);
            };
            document.getElementById('app-flow').addEventListener('click', e => {
                const button = e.target.closest('button');
                if (!button) return;
                show(frames.findIndex(frame => frame.dataset.step === button.dataset.step));
                play();
            });
            new IntersectionObserver(([entry]) => {
                entry.isIntersecting ? play() : clearInterval(timer);
            }, { threshold: .4 }).observe(document.getElementById('app-tour'));
        })();
    </script>
@endsection
