<x-filament-panels::page>
    <div
        class="farah-checkin"
        x-data="farahCheckIn"
        x-on:farah-checkin-done.window="done($event.detail)"
    >
        <x-filament::section>
            <form wire:submit="checkIn" class="farah-checkin-form">
                {{-- Camera scanner (html5-qrcode, loaded on demand from cdnjs). Typing / USB scanners keep working. --}}
                <div class="farah-checkin-camera" wire:ignore>
                    <button type="button" class="farah-checkin-camera-btn" x-show="! on" x-on:click="start()" x-bind:disabled="loading">
                        <x-filament::icon icon="heroicon-o-camera" class="farah-checkin-camera-icon" />
                        <span x-show="! loading">{{ __('provider.checkin.scan_camera') }}</span>
                        <span x-show="loading" x-cloak>{{ __('provider.checkin.camera_loading') }}</span>
                    </button>
                    <div x-show="on" x-cloak class="farah-checkin-reader-wrap">
                        <div id="farah-reader" class="farah-checkin-reader"></div>
                        <p class="farah-checkin-hint">{{ __('provider.checkin.camera_hint') }}</p>
                        <button type="button" class="farah-checkin-camera-stop" x-on:click="stop()">
                            {{ __('provider.checkin.stop_camera') }}
                        </button>
                    </div>
                    <p x-show="error" x-cloak class="farah-checkin-error">{{ __('provider.checkin.camera_error') }}</p>
                </div>

                {{-- A phone as the scanner: better camera than most laptops. The phone opens the link in the QR. --}}
                <div class="farah-checkin-phone">
                    @if ($this->phoneToken)
                        <div class="farah-checkin-phone-box" wire:poll.2s="pollPhone">
                            <img src="{{ $this->phoneQr() }}" alt="" class="farah-checkin-phone-qr">
                            <div class="farah-checkin-phone-text">
                                <strong>{{ __('provider.checkin.phone_scan_me') }}</strong>
                                <span @class(['farah-checkin-phone-state', 'is-on' => $this->phoneOnline()])>
                                    {{ $this->phoneOnline() ? __('provider.checkin.phone_connected') : __('provider.checkin.phone_waiting') }}
                                </span>
                                <span class="farah-checkin-phone-note">{{ __('provider.checkin.phone_note') }}</span>
                                <button type="button" class="farah-checkin-camera-stop" wire:click="disconnectPhone">{{ __('provider.checkin.phone_disconnect') }}</button>
                            </div>
                        </div>
                    @else
                        <button type="button" class="farah-checkin-phone-btn" wire:click="connectPhone" wire:loading.attr="disabled" wire:target="connectPhone">
                            <x-filament::icon icon="heroicon-o-device-phone-mobile" class="farah-checkin-camera-icon" />
                            <span>{{ __('provider.checkin.phone_connect') }}</span>
                        </button>
                    @endif
                </div>

                <label class="farah-checkin-label" for="farah-checkin-code">{{ __('provider.checkin.code') }}</label>
                <input
                    id="farah-checkin-code"
                    type="text"
                    wire:model="code"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    inputmode="text"
                    enterkeyhint="go"
                    autofocus
                    x-init="$nextTick(() => $el.focus())"
                    placeholder="{{ __('provider.checkin.placeholder') }}"
                    class="farah-checkin-input"
                >
                @error('code')
                    <p class="farah-checkin-error">{{ $message }}</p>
                @enderror

                {{-- A provider has one store: the "which place?" question only appears if there is ever more than one. --}}
                @if (count($this->getVenueOptions()) > 1)
                    <label class="farah-checkin-label" for="farah-checkin-venue">{{ __('provider.checkin.venue') }}</label>
                    <select id="farah-checkin-venue" wire:model="venueId" class="farah-checkin-select">
                        <option value="">{{ __('provider.checkin.any_venue') }}</option>
                        @foreach ($this->getVenueOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @endif

                <x-filament::button type="submit" size="xl" icon="heroicon-o-check-badge" class="farah-checkin-btn" wire:loading.attr="disabled" wire:target="checkIn">
                    {{ __('provider.checkin.submit') }}
                </x-filament::button>
                <p class="farah-checkin-hint">{{ __('provider.checkin.hint') }}</p>
            </form>
        </x-filament::section>

        @if ($result)
            <div
                wire:key="checkin-result-{{ count($recent) }}"
                @class(['farah-checkin-result', 'is-ok' => $result['ok'], 'is-error' => ! $result['ok']])
                role="status"
                aria-live="assertive"
            >
                <div class="farah-checkin-result-icon" aria-hidden="true">{{ $result['ok'] ? '✓' : '✕' }}</div>
                <div class="farah-checkin-result-body">
                    <div class="farah-checkin-result-title">{{ $result['message'] }}</div>
                    <div class="farah-checkin-result-code">{{ $result['code'] }}</div>
                    @if ($result['ok'])
                        <dl class="farah-checkin-meta">
                            <div><dt>{{ __('provider.fields.activity') }}</dt><dd>{{ $result['activity'] ?? '-' }}</dd></div>
                            <div><dt>{{ __('provider.fields.resource') }}</dt><dd>{{ $result['resource'] ?? '-' }}</dd></div>
                            <div><dt>{{ __('provider.fields.time') }}</dt><dd><bdi dir="ltr">{{ $result['window'] ?? '-' }}</bdi></dd></div>
                            <div><dt>{{ __('provider.fields.customer') }}</dt><dd>{{ $result['customer'] ?? '-' }}</dd></div>
                            <div><dt>{{ __('provider.fields.venue') }}</dt><dd>{{ $result['venue'] ?? '-' }}</dd></div>
                            <div><dt>{{ __('provider.fields.order') }}</dt><dd>{{ $result['order'] ?? '-' }}</dd></div>
                            <div><dt>{{ __('provider.fields.checked_in_at') }}</dt><dd><bdi dir="ltr">{{ $result['time'] ?? '-' }}</bdi></dd></div>
                        </dl>
                    @endif
                    <div class="farah-checkin-next">{{ __('provider.checkin.next') }}</div>
                </div>
            </div>
        @endif

        @if (count($recent) > 1)
            <x-filament::section :heading="__('provider.checkin.recent')" compact>
                <ul class="farah-checkin-recent">
                    @foreach ($recent as $row)
                        <li @class(['is-ok' => $row['ok'], 'is-error' => ! $row['ok']])>
                            <span class="farah-checkin-recent-dot" aria-hidden="true">{{ $row['ok'] ? '✓' : '✕' }}</span>
                            <span class="farah-checkin-recent-code">{{ $row['code'] }}</span>
                            <span class="farah-checkin-recent-label">{{ $row['label'] }}</span>
                            <span class="farah-checkin-recent-time">{{ $row['time'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    </div>

    @script
    <script>
        Alpine.data('farahCheckIn', () => {
            return {
                on: false,
                loading: false,
                error: false,
                busy: false,
                scanner: null,

                loadLibrary() {
                    if (window.Html5Qrcode) return Promise.resolve();
                    return new Promise((resolve, reject) => {
                        const s = document.createElement('script');
                        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js';
                        s.onload = resolve;
                        s.onerror = reject;
                        document.head.appendChild(s);
                    });
                },

                async start() {
                    this.error = false;
                    this.loading = true;
                    try {
                        await this.loadLibrary();
                        this.on = true;
                        await this.$nextTick();
                        this.scanner = new window.Html5Qrcode('farah-reader');
                        await this.scanner.start(
                            { facingMode: 'environment' },
                            { fps: 10, qrbox: (w, h) => { const m = Math.floor(Math.min(w, h) * 0.75); return { width: m, height: m }; } },
                            (text) => this.found(text),
                            () => {},
                        );
                    } catch (e) {
                        this.error = true;
                        this.on = false;
                        this.scanner = null;
                    }
                    this.loading = false;
                },

                async stop() {
                    try {
                        if (this.scanner) {
                            await this.scanner.stop();
                            this.scanner.clear();
                        }
                    } catch (e) {}
                    this.scanner = null;
                    this.on = false;
                },

                // Booking QR codes may carry the bare code, a URL (…/bookings/CODE or ?code=CODE) or JSON {"code": …}.
                extract(text) {
                    let value = String(text || '').trim();
                    try {
                        const json = JSON.parse(value);
                        if (json && json.code) value = String(json.code);
                    } catch (e) {}
                    if (/^https?:\/\//i.test(value)) {
                        try {
                            const url = new URL(value);
                            value = url.searchParams.get('code') || url.pathname.split('/').filter(Boolean).pop() || value;
                        } catch (e) {}
                    }
                    return value.toUpperCase().replace(/[^A-Z0-9-]/g, '').slice(0, 30);
                },

                found(text) {
                    if (this.busy) return;
                    const code = this.extract(text);
                    if (! code) return;
                    this.busy = true;
                    this.$wire.code = code;
                    this.$wire.checkIn().finally(() => setTimeout(() => { this.busy = false; }, 1800));
                },

                done(detail) {
                    const ok = detail && (detail.ok ?? (Array.isArray(detail) ? detail[0]?.ok : undefined));
                    try { if (navigator.vibrate) navigator.vibrate(ok ? 80 : [120, 80, 120]); } catch (e) {}
                    try {
                        const Ctx = window.AudioContext || window.webkitAudioContext;
                        if (Ctx) {
                            const ctx = this._audio || (this._audio = new Ctx());
                            const osc = ctx.createOscillator();
                            const gain = ctx.createGain();
                            osc.frequency.value = ok ? 880 : 220;
                            osc.connect(gain); gain.connect(ctx.destination);
                            gain.gain.setValueAtTime(0.15, ctx.currentTime);
                            osc.start(); osc.stop(ctx.currentTime + (ok ? 0.12 : 0.35));
                        }
                    } catch (e) {}
                    if (! this.on) {
                        this.$nextTick(() => document.getElementById('farah-checkin-code')?.focus());
                    }
                },

                destroy() {
                    this.stop();
                },
            };
        });
    </script>
    @endscript

    <style>
        .farah-checkin { max-width: 40rem; margin-inline: auto; display: grid; gap: 1.25rem; }
        .farah-checkin-form { display: grid; gap: .75rem; }
        .farah-checkin-label { font-weight: 600; font-size: .9rem; }
        .farah-checkin-input {
            width: 100%; font-size: clamp(1.4rem, 6vw, 2rem); line-height: 1.2; padding: .9rem 1rem; text-align: center;
            letter-spacing: .2em; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; text-transform: uppercase;
            border-radius: .9rem; border: 2px solid rgba(127,127,127,.35); background: transparent; direction: ltr;
        }
        .farah-checkin-input:focus { outline: none; border-color: #B21FD1; box-shadow: 0 0 0 4px rgba(178,31,209,.18); }
        .farah-checkin-select { width: 100%; padding: .75rem .8rem; border-radius: .6rem; border: 1px solid rgba(127,127,127,.35); background: transparent; font-size: 1rem; }
        .dark .farah-checkin-select option { background: #18181b; }
        .farah-checkin-btn { width: 100%; justify-content: center; padding-block: .9rem; font-size: 1.1rem; }
        .farah-checkin-hint { font-size: .8rem; opacity: .65; text-align: center; }
        .farah-checkin-error { color: #e11d48; font-size: .85rem; }
        .farah-checkin-camera { display: grid; gap: .6rem; }
        .farah-checkin-camera-btn {
            display: flex; align-items: center; justify-content: center; gap: .6rem; width: 100%;
            padding: 1rem; border-radius: .9rem; font-weight: 700; font-size: 1.05rem; color: #fff;
            background: var(--primary-600); box-shadow: none;
        }
        .farah-checkin-camera-btn:disabled { opacity: .7; }
        .farah-checkin-camera-icon { width: 1.5rem; height: 1.5rem; }
        .farah-checkin-phone-btn {
            display: flex; align-items: center; justify-content: center; gap: .6rem; width: 100%;
            padding: .85rem; border-radius: .9rem; font-weight: 700; border: 1.5px solid var(--primary-600); color: var(--primary-600);
        }
        .farah-checkin-phone-btn:disabled { opacity: .6; }
        .farah-checkin-phone-box { display: flex; gap: 1rem; align-items: center; padding: 1rem; border-radius: .9rem; border: 1.5px dashed rgba(127,127,127,.45); }
        .farah-checkin-phone-qr { width: 11rem; height: 11rem; flex-shrink: 0; background: #fff; border-radius: .6rem; padding: .35rem; }
        .farah-checkin-phone-text { display: grid; gap: .45rem; justify-items: start; min-width: 0; }
        .farah-checkin-phone-state { font-size: .85rem; font-weight: 700; padding: .2rem .7rem; border-radius: 999px; background: rgba(127,127,127,.15); }
        .farah-checkin-phone-state.is-on { background: #0F7B55; color: #fff; }
        .farah-checkin-phone-note { font-size: .8rem; opacity: .7; }
        @media (max-width: 640px) { .farah-checkin-phone-box { flex-direction: column; text-align: center; } .farah-checkin-phone-text { justify-items: center; } }
        .farah-checkin-reader-wrap { display: grid; gap: .5rem; }
        .farah-checkin-reader { width: 100%; border-radius: .9rem; overflow: hidden; background: #000; min-height: 12rem; }
        .farah-checkin-reader video { width: 100% !important; height: auto !important; object-fit: cover; }
        .farah-checkin-camera-stop { justify-self: center; font-size: .9rem; padding: .45rem 1rem; border-radius: 999px; border: 1px solid rgba(127,127,127,.4); }
        .farah-checkin-result { display: flex; gap: 1rem; padding: 1.5rem 1.25rem; border-radius: 1rem; color: #fff; align-items: flex-start; animation: farah-pop .25s ease-out; }
        .farah-checkin-result.is-ok { background: #0F7B55; }
        .farah-checkin-result.is-error { background: #B42339; }
        .farah-checkin-result-icon {
            flex-shrink: 0; width: 4rem; height: 4rem; border-radius: 999px; display: flex; align-items: center; justify-content: center;
            font-size: 2.4rem; line-height: 1; font-weight: 800; background: rgba(255,255,255,.2);
        }
        .farah-checkin-result-body { min-width: 0; flex: 1; }
        .farah-checkin-result-title { font-size: clamp(1.2rem, 5vw, 1.6rem); font-weight: 800; }
        .farah-checkin-result-code { font-family: ui-monospace, monospace; letter-spacing: .2em; opacity: .9; direction: ltr; text-align: start; word-break: break-all; }
        .farah-checkin-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .5rem 1rem; margin-top: .75rem; font-size: .9rem; }
        .farah-checkin-meta dt { opacity: .75; font-size: .75rem; }
        .farah-checkin-meta dd { font-weight: 600; overflow-wrap: anywhere; }
        .farah-checkin-next { margin-top: .75rem; font-size: .8rem; opacity: .85; }
        .farah-checkin-recent { display: grid; gap: .35rem; font-size: .85rem; }
        .farah-checkin-recent li { display: flex; align-items: center; gap: .6rem; padding: .35rem .5rem; border-radius: .5rem; background: rgba(127,127,127,.06); }
        .farah-checkin-recent-dot { font-weight: 800; width: 1.2rem; text-align: center; }
        .farah-checkin-recent li.is-ok .farah-checkin-recent-dot { color: #059669; }
        .farah-checkin-recent li.is-error .farah-checkin-recent-dot { color: #e11d48; }
        .farah-checkin-recent-code { font-family: ui-monospace, monospace; direction: ltr; }
        .farah-checkin-recent-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: .8; }
        .farah-checkin-recent-time { opacity: .6; font-variant-numeric: tabular-nums; }
        @keyframes farah-pop { from { transform: scale(.97); opacity: .4; } to { transform: scale(1); opacity: 1; } }
        @media (max-width: 640px) {
            .farah-checkin-result { flex-direction: column; align-items: center; text-align: center; }
            .farah-checkin-result-code { text-align: center; }
        }
    </style>
</x-filament-panels::page>
