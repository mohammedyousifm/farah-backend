<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

/**
 * Readable view of a JSON payload for the admin (TODAY-05, Integration Logs).
 *  - structured(): key / value rows, nested objects and arrays fold open and shut (<details>),
 *    long values are shortened with "show full value" and "copy value".
 *  - raw(): indented, colour-highlighted JSON.
 * Everything is escaped here; values are masked by Redactor first. Links are never opened by themselves:
 * an "open link" button appears only for https URLs on an allowed host.
 */
class PayloadTree
{
    public const LONG = 90;            // characters before a value is shortened

    public const MAX_NODES = 1500;     // a huge payload shows the first rows only (raw view still has it all)

    public const MAX_RAW_BYTES = 400_000;

    private int $nodes = 0;

    private bool $cut = false;

    /** @param  array<int, string>  $allowedHosts */
    public function __construct(private array $allowedHosts = []) {}

    /**
     * Turns whatever the column holds into [decoded value, is it valid JSON data].
     *
     * @return array{0: mixed, 1: bool}
     */
    public static function decode(mixed $state): array
    {
        if (is_string($state)) {
            $decoded = json_decode($state, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [$state, false];
            }
            $state = $decoded;
        }

        return [Redactor::redact($state), is_array($state)];
    }

    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public function structured(array $value): HtmlString
    {
        $this->nodes = 0;
        $this->cut = false;
        $html = $this->rows($value, 0);
        if ($this->cut) {
            $html .= '<div class="fj-note">'.e(__('admin.payload.too_big')).'</div>';
        }

        return new HtmlString($html);
    }

    public function wasCut(): bool
    {
        return $this->cut;
    }

    public static function raw(string $json): HtmlString
    {
        if (strlen($json) > self::MAX_RAW_BYTES) {
            $json = mb_strcut($json, 0, self::MAX_RAW_BYTES)."\n…";
        }
        $html = e($json);
        // Keys, strings, numbers, true/false/null, on already escaped text (quotes are &quot;).
        $html = preg_replace_callback(
            '/(&quot;(?:(?!&quot;).)*?&quot;)(\s*:)?|\b(true|false|null)\b|(-?\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b)/u',
            function (array $m) {
                if (($m[1] ?? '') !== '') {
                    return '<span class="'.(($m[2] ?? '') !== '' ? 'fj-k' : 'fj-s').'">'.$m[1].'</span>'.($m[2] ?? '');
                }
                if (($m[3] ?? '') !== '') {
                    return '<span class="fj-b">'.$m[3].'</span>';
                }

                return '<span class="fj-n">'.$m[4].'</span>';
            },
            $html,
        ) ?? $html;

        return new HtmlString($html);
    }

    private function rows(array $value, int $depth): string
    {
        if ($value === []) {
            return '<div class="fj-empty">'.(array_is_list($value) ? '[ ]' : '{ }').'</div>';
        }

        $out = '<dl class="fj-rows">';
        foreach ($value as $key => $item) {
            if (++$this->nodes > self::MAX_NODES) {
                $this->cut = true;
                break;
            }
            $label = '<dt class="fj-key">'.e((string) $key).'</dt>';

            if (is_array($item)) {
                $summary = array_is_list($item)
                    ? e(trans_choice('admin.payload.items', count($item), ['count' => count($item)]))
                    : e(trans_choice('admin.payload.fields', count($item), ['count' => count($item)]));
                $out .= '<div class="fj-row fj-nested">'
                    .'<details'.($depth < 1 ? ' open' : '').'><summary>'.$label.'<span class="fj-sum">'.$summary.'</span></summary>'
                    .'<dd>'.$this->rows($item, $depth + 1).'</dd></details></div>';

                continue;
            }

            $out .= '<div class="fj-row">'.$label.'<dd>'.$this->scalar($item).'</dd></div>';
        }

        return $out.'</dl>';
    }

    private function scalar(mixed $item): string
    {
        if ($item === null) {
            return '<span class="fj-b">null</span>';
        }
        if (is_bool($item)) {
            return '<span class="fj-b">'.($item ? 'true' : 'false').'</span>';
        }
        if (is_int($item) || is_float($item)) {
            return '<span class="fj-n">'.e((string) $item).'</span>';
        }

        $text = (string) $item;
        $copy = '<button type="button" class="fj-btn" x-on:click="copy('.e(Js::from($text)).')">'.e(__('admin.payload.copy_value')).'</button>';
        $open = $this->safeLink($text);
        $open = $open ? '<a class="fj-btn" href="'.e($open).'" target="_blank" rel="noopener noreferrer nofollow">'.e(__('admin.payload.open_link')).'</a>' : '';

        if (mb_strlen($text) <= self::LONG) {
            return '<span class="fj-s fj-val">'.e($text).'</span>'.($open || mb_strlen($text) > 30 ? '<span class="fj-acts">'.$copy.$open.'</span>' : '');
        }

        return '<span x-data="{ full: false }" class="fj-long">'
            .'<span class="fj-s fj-val" x-show="! full">'.e(mb_substr($text, 0, self::LONG)).'…</span>'
            .'<span class="fj-s fj-val" x-show="full" x-cloak>'.e($text).'</span>'
            .'<span class="fj-acts"><button type="button" class="fj-btn" x-on:click="full = ! full" x-text="full ? '.e(Js::from(__('admin.payload.show_less'))).' : '.e(Js::from(__('admin.payload.show_full'))).'">'.e(__('admin.payload.show_full')).'</button>'
            .$copy.$open.'</span></span>';
    }

    /** An https URL on an allowed host, or null. Values with masked parts are not offered either. */
    private function safeLink(string $text): ?string
    {
        if (! str_starts_with($text, 'https://') || str_contains($text, Redactor::MASK) || preg_match('/\s/', $text)) {
            return null;
        }
        $host = strtolower((string) parse_url($text, PHP_URL_HOST));

        return $host !== '' && in_array($host, $this->allowedHosts, true) ? $text : null;
    }

    /** Hosts the "open link" button may point at: this site plus any listed in config('farah.integrations.link_hosts'). */
    public static function allowedHosts(): array
    {
        $hosts = (array) config('farah.integrations.link_hosts', []);
        $hosts[] = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return array_values(array_unique(array_filter(array_map('strtolower', $hosts))));
    }
}
