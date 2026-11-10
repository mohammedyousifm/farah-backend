{{-- Integration Logs: one request / response payload, readable view + raw JSON (TODAY-05). --}}
@php
    use App\Support\PayloadTree;

    [$value, $isJson] = PayloadTree::decode($getState());
    $isEmpty = $value === null || $value === [] || $value === '';
    $raw = $isJson ? PayloadTree::json($value) : (is_scalar($value) ? (string) $value : PayloadTree::json($value));
    $tree = new PayloadTree(PayloadTree::allowedHosts());
@endphp

<div class="fj" x-data="{
        tab: @js($isJson ? 'tree' : 'raw'),
        copied: false,
        copy(text) {
            const done = () => { this.copied = true; setTimeout(() => this.copied = false, 1500) };
            if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); return }
            const t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select();
            document.execCommand('copy'); t.remove(); done();
        },
        fold(open) { this.$root.querySelectorAll('details').forEach(d => d.open = open) },
    }">
    @if ($isEmpty)
        <div class="fj-empty">{{ __('admin.payload.empty') }}</div>
    @else
        <div class="fj-bar">
            @if ($isJson)
                <div class="fj-tabs" role="tablist">
                    <button type="button" role="tab" x-on:click="tab = 'tree'" :aria-selected="tab === 'tree'" :class="tab === 'tree' && 'is-on'">{{ __('admin.payload.structured') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'raw'" :aria-selected="tab === 'raw'" :class="tab === 'raw' && 'is-on'">{{ __('admin.payload.raw') }}</button>
                </div>
            @else
                <span class="fj-note">{{ __('admin.payload.not_json') }}</span>
            @endif
            <div class="fj-tools">
                @if ($isJson)
                    <button type="button" class="fj-btn" x-show="tab === 'tree'" x-on:click="fold(true)">{{ __('admin.payload.expand') }}</button>
                    <button type="button" class="fj-btn" x-show="tab === 'tree'" x-on:click="fold(false)">{{ __('admin.payload.collapse') }}</button>
                @endif
                <button type="button" class="fj-btn" x-on:click="copy(@js($raw))">{{ $isJson ? __('admin.payload.copy_json') : __('admin.payload.copy_value') }}</button>
                <span class="fj-copied" x-show="copied" x-cloak>{{ __('admin.payload.copied') }}</span>
            </div>
        </div>

        @if ($isJson)
            <div class="fj-tree" x-show="tab === 'tree'">{{ $tree->structured($value) }}</div>
        @endif
        <pre class="fj-raw farah-json" x-show="tab === 'raw'" @if ($isJson) x-cloak @endif>{{ $isJson ? PayloadTree::raw($raw) : $raw }}</pre>
    @endif
</div>
