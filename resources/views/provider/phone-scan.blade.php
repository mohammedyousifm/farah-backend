<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('provider.checkin.phone_title') }}</title>
    <link rel="icon" href="{{ asset('images/favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Almarai', system-ui, sans-serif; background: #111014; color: #fff; min-height: 100dvh; display: flex; flex-direction: column; }
        header { padding: 1rem 1.1rem .6rem; }
        header h1 { margin: 0; font-size: 1.15rem; font-weight: 800; }
        header p { margin: .2rem 0 0; font-size: .85rem; opacity: .7; }
        main { flex: 1; display: grid; gap: .9rem; padding: .4rem 1.1rem 1.4rem; align-content: start; }
        #reader { width: 100%; border-radius: 1rem; overflow: hidden; background: #000; min-height: 15rem; }
        #reader video { width: 100% !important; height: auto !important; object-fit: cover; }
        .btn { display: flex; align-items: center; justify-content: center; gap: .5rem; width: 100%; padding: 1rem; border: 0; border-radius: .9rem; font: inherit; font-weight: 800; font-size: 1.05rem; color: #fff; background: #B21FD1; }
        .btn.ghost { background: transparent; border: 1px solid rgba(255,255,255,.3); font-weight: 700; font-size: .95rem; padding: .8rem; }
        .btn:disabled { opacity: .6; }
        .note { font-size: .82rem; opacity: .7; text-align: center; margin: 0; line-height: 1.6; }
        .manual { display: flex; gap: .5rem; }
        .manual input { flex: 1; min-width: 0; padding: .85rem; border-radius: .8rem; border: 1px solid rgba(255,255,255,.3); background: transparent; color: #fff; font: inherit; font-size: 1.1rem; text-align: center; letter-spacing: .15em; text-transform: uppercase; direction: ltr; }
        .manual button { width: auto; padding-inline: 1.2rem; }
        .result { border-radius: 1rem; padding: 1.2rem; display: none; text-align: center; animation: pop .2s ease-out; }
        .result.ok { display: block; background: #0F7B55; }
        .result.bad { display: block; background: #B42339; }
        .result .mark { font-size: 2.6rem; line-height: 1; font-weight: 800; }
        .result .title { font-size: 1.25rem; font-weight: 800; margin-top: .3rem; }
        .result .code { font-family: ui-monospace, monospace; letter-spacing: .18em; opacity: .9; direction: ltr; margin-top: .2rem; word-break: break-all; }
        .result .meta { margin-top: .6rem; font-size: .95rem; line-height: 1.7; }
        [hidden] { display: none !important; }
        @keyframes pop { from { transform: scale(.96); opacity: .4; } to { transform: scale(1); opacity: 1; } }
    </style>
</head>
<body>
    <header>
        <h1>{{ __('provider.checkin.phone_title') }}</h1>
        <p>{{ app()->getLocale() === 'en' ? ($provider->name_en ?: $provider->name_ar) : $provider->name_ar }}</p>
    </header>

    <main>
        <div id="result" class="result" role="status" aria-live="assertive">
            <div class="mark" id="result-mark"></div>
            <div class="title" id="result-title"></div>
            <div class="code" id="result-code"></div>
            <div class="meta" id="result-meta"></div>
        </div>

        <div id="reader" hidden></div>

        <button type="button" class="btn" id="start">{{ __('provider.checkin.scan_camera') }}</button>
        <button type="button" class="btn ghost" id="stop" hidden>{{ __('provider.checkin.stop_camera') }}</button>

        {{-- Without HTTPS a browser refuses live camera access; taking a photo of the code still works. --}}
        <label class="btn ghost" id="photo-label">
            {{ __('provider.checkin.phone_photo') }}
            <input type="file" id="photo" accept="image/*" capture="environment" hidden>
        </label>
        <div id="photo-reader" hidden></div>

        <form class="manual" id="manual">
            <input type="text" id="code" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="30" placeholder="{{ __('provider.checkin.placeholder') }}">
            <button type="submit" class="btn">{{ __('provider.checkin.phone_send') }}</button>
        </form>

        <p class="note" id="note">{{ __('provider.checkin.phone_hint') }}</p>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
    <script>
        (() => {
            const url = {{ Js::from(route('provider.phone-scan.scan', $token)) }};
            const csrf = document.querySelector('meta[name=csrf-token]').content;
            const text = {{ Js::from($text) }};
            const $ = (id) => document.getElementById(id);
            let scanner = null;
            let busy = false;
            let last = { code: '', at: 0 };

            // Booking QR codes may carry the bare code, a URL (…/bookings/CODE or ?code=CODE) or JSON {"code": …}.
            const extract = (raw) => {
                let value = String(raw || '').trim();
                try { const json = JSON.parse(value); if (json && json.code) value = String(json.code); } catch (e) {}
                if (/^https?:\/\//i.test(value)) {
                    try { const u = new URL(value); value = u.searchParams.get('code') || u.pathname.split('/').filter(Boolean).pop() || value; } catch (e) {}
                }
                return value.toUpperCase().replace(/[^A-Z0-9-]/g, '').slice(0, 30);
            };

            const show = (ok, title, code, meta) => {
                const box = $('result');
                box.className = 'result ' + (ok ? 'ok' : 'bad');
                $('result-mark').textContent = ok ? '✓' : '✕';
                $('result-title').textContent = title || '';
                $('result-code').textContent = code || '';
                $('result-meta').textContent = meta || '';
                try { if (navigator.vibrate) navigator.vibrate(ok ? 80 : [120, 80, 120]); } catch (e) {}
                beep(ok);
                box.scrollIntoView({ behavior: 'smooth', block: 'start' });
            };

            // A short high tone for "checked in", a longer low one for "refused": staff need not look at the phone.
            let audio = null;
            const beep = (ok) => {
                try {
                    const Ctx = window.AudioContext || window.webkitAudioContext;
                    if (! Ctx) return;
                    audio = audio || new Ctx();
                    const osc = audio.createOscillator();
                    const gain = audio.createGain();
                    osc.frequency.value = ok ? 880 : 220;
                    osc.connect(gain); gain.connect(audio.destination);
                    gain.gain.setValueAtTime(0.18, audio.currentTime);
                    osc.start(); osc.stop(audio.currentTime + (ok ? 0.12 : 0.35));
                } catch (e) {}
            };

            // The phone must not dim or lock between customers.
            let wakeLock = null;
            const stayAwake = async () => {
                try { if ('wakeLock' in navigator) wakeLock = await navigator.wakeLock.request('screen'); } catch (e) {}
            };
            document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') stayAwake(); });
            stayAwake();

            const post = (body) => fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(body),
            });

            const send = async (raw) => {
                const code = extract(raw);
                if (! code) { show(false, text.no_code); return; }
                // The camera keeps seeing the same code for a moment: one scan, one check-in.
                if (busy || (code === last.code && Date.now() - last.at < 4000)) return;
                busy = true;
                last = { code, at: Date.now() };
                try {
                    const res = await post({ code });
                    if (res.status === 404 || res.status === 419) { show(false, text.expired); return; }
                    const data = await res.json();
                    if (! res.ok) { show(false, data.message || text.offline, code); return; }
                    show(data.ok, data.message, data.code, data.ok ? [data.customer, data.activity, data.resource, data.window ? '⁦' + data.window + '⁩' : ''].filter(Boolean).join(' · ') : '');
                } catch (e) {
                    show(false, text.offline, code);
                } finally {
                    setTimeout(() => { busy = false; }, 1200);
                }
            };

            $('start').addEventListener('click', async () => {
                $('start').disabled = true;
                try {
                    $('reader').hidden = false;
                    scanner = new Html5Qrcode('reader');
                    await scanner.start(
                        { facingMode: 'environment' },
                        { fps: 10, qrbox: (w, h) => { const m = Math.floor(Math.min(w, h) * 0.75); return { width: m, height: m }; } },
                        send,
                        () => {},
                    );
                    $('start').hidden = true;
                    $('stop').hidden = false;
                } catch (e) {
                    scanner = null;
                    $('reader').hidden = true;
                    $('note').textContent = text.camera_error;
                }
                $('start').disabled = false;
            });

            $('stop').addEventListener('click', async () => {
                try { if (scanner) { await scanner.stop(); scanner.clear(); } } catch (e) {}
                scanner = null;
                $('reader').hidden = true;
                $('stop').hidden = true;
                $('start').hidden = false;
            });

            $('photo').addEventListener('change', async (event) => {
                const file = event.target.files[0];
                event.target.value = '';
                if (! file) return;
                try {
                    send(await new Html5Qrcode('photo-reader').scanFile(file, false));
                } catch (e) {
                    show(false, text.no_code);
                }
            });

            $('manual').addEventListener('submit', (event) => {
                event.preventDefault();
                send($('code').value);
                $('code').value = '';
            });

            // Tells the computer the phone is still here.
            setInterval(() => post({ ping: true }).catch(() => {}), 30000);
        })();
    </script>
</body>
</html>
