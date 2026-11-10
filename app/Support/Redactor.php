<?php

namespace App\Support;

/**
 * Masks secrets in payloads before they are stored or shown (TODAY-05): tokens, keys, passwords, signatures,
 * one-time codes and card data, by key name, plus the same names inside URL query strings
 * (a signed checkout link carries its `signature=`).
 */
class Redactor
{
    public const MASK = '••••••';

    /** Key names (any case, any part of the name) whose values are never shown. */
    private const SENSITIVE = '/(secret|token|password|passwd|signature|authori[sz]ation|api[_-]?key|private[_-]?key|access[_-]?key|client[_-]?secret|cvv|cvc|card[_-]?number|^pan$|^otp$|otp[_-]?code|^pin$|cookie|session)/i';

    /** Payloads deeper than this are cut off (a runaway structure must not take the page down). */
    private const MAX_DEPTH = 12;

    public static function isSensitiveKey(string|int $key): bool
    {
        return is_string($key) && preg_match(self::SENSITIVE, $key) === 1;
    }

    public static function redact(mixed $value, int $depth = 0): mixed
    {
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return '[…]';
            }
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::isSensitiveKey($key) && $item !== null && $item !== ''
                    ? self::MASK
                    : self::redact($item, $depth + 1);
            }

            return $out;
        }

        return is_string($value) ? self::redactUrl($value) : $value;
    }

    /** "https://x/pay?ref=1&signature=abc" → "https://x/pay?ref=1&signature=••••••" */
    public static function redactUrl(string $value): string
    {
        if (! str_contains($value, '?') || ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            return $value;
        }

        return (string) preg_replace_callback('/([?&])([^=&#]+)=([^&#]*)/', function (array $m) {
            return self::isSensitiveKey(urldecode($m[2])) ? $m[1].$m[2].'='.self::MASK : $m[0];
        }, $value);
    }
}
