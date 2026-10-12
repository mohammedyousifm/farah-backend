<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A link that opens a place on Google Maps (the share link or the full address-bar link). */
class GoogleMapsLink implements ValidationRule
{
    private const HOSTS = ['maps.app.goo.gl', 'goo.gl', 'maps.google.com', 'google.com', 'www.google.com', 'g.co'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = parse_url((string) $value);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        $ok = ($parts['scheme'] ?? '') === 'https' && in_array($host, self::HOSTS, true) && match ($host) {
            'goo.gl', 'google.com', 'www.google.com' => str_starts_with($path, '/maps'),
            'g.co' => str_starts_with($path, '/kgs'),
            default => true,
        };

        if (! $ok) {
            $fail(__('validation.custom.maps_url.google'));
        }
    }
}
