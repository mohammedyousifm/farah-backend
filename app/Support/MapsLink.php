<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Reads the point (and the place's name/address when the link carries it) out of a Google Maps link, so the
 * provider pastes the link once and the store's coordinates, address and city fill themselves.
 * Short share links (maps.app.goo.gl/...) are followed to the full link first; results are cached.
 */
class MapsLink
{
    /** Short-link hosts that are followed (only these: the request never goes anywhere else first). */
    private const SHORT_HOSTS = ['maps.app.goo.gl', 'goo.gl', 'g.co'];

    /** @return array{lat: float, lng: float, address: ?string}|null */
    public static function resolve(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            return null;
        }

        // Only a found point is kept: a link that failed (no network, Google busy) is tried again next time.
        $key = 'maps-link:'.sha1($url);
        if ($point = Cache::get($key)) {
            return $point;
        }
        $full = self::expand($url);
        $point = (self::parse($full) ?: self::fromPage($full)) ?: null;
        if ($point) {
            Cache::put($key, $point, now()->addDays(30));
        }

        return $point;
    }

    /** The full Google Maps address a short link leads to (the link itself when it is already full). */
    private static function expand(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! in_array($host, self::SHORT_HOSTS, true)) {
            return $url;
        }

        try {
            $response = Http::timeout(5)->withOptions([
                'allow_redirects' => ['max' => 5, 'protocols' => ['https'], 'track_redirects' => true,
                    // Every hop must stay on Google: an old goo.gl link can point anywhere, and the server must
                    // never be walked to an internal address (or any other site) on a provider's behalf.
                    'on_redirect' => function ($request, $response, UriInterface $uri) {
                        if (! self::isGoogleHost($uri->getHost())) {
                            throw new \RuntimeException('Redirect away from Google Maps');
                        }
                    }],
            ])->get($url);

            $hops = $response->header('X-Guzzle-Redirect-History');

            return $hops !== '' ? last(explode(', ', $hops)) : $url;
        } catch (Throwable) {
            return $url;
        }
    }

    /** google.com and its subdomains (www, maps, consent) or one of the short-link hosts. */
    public static function isGoogleHost(string $host): bool
    {
        $host = strtolower($host);

        return in_array($host, self::SHORT_HOSTS, true) || $host === 'google.com' || str_ends_with($host, '.google.com');
    }

    /** @return array{lat: float, lng: float, address: ?string}|false */
    public static function parse(string $url): array|false
    {
        // Consent pages wrap the real link in ?continue=...
        $text = rawurldecode(rawurldecode($url));
        $number = '(-?\d{1,3}\.\d+)';

        // The pin of a place (!3d lat !4d lng) is exact; @lat,lng is only where the map was centred.
        foreach (["/!3d{$number}!4d{$number}/", "/[?&](?:q|query|ll|destination)=(?:loc:)?{$number},\s*{$number}/", "/\/place\/{$number},{$number}/", "/@{$number},{$number}/"] as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
                if (abs($lat) <= 90 && abs($lng) <= 180) {
                    return ['lat' => round($lat, 7), 'lng' => round($lng, 7), 'address' => self::placeText($text)];
                }
            }
        }

        return false;
    }

    /**
     * A link that names a place without coordinates ("/maps/place/Boulevard+World" or "?q=an address"): the Maps
     * page itself opens centred on that place, as [zoom distance, lng, lat] in APP_INITIALIZATION_STATE.
     *
     * @return array{lat: float, lng: float, address: ?string}|false
     */
    private static function fromPage(string $url): array|false
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        // Only a link that names a place: the plain map opens wherever the visitor is, which says nothing.
        if (! in_array($host, ['www.google.com', 'google.com', 'maps.google.com'], true)
            || ! preg_match('#/maps/place/|[?&](q|query)=#', $url)) {
            return false;
        }

        try {
            $html = Http::timeout(6)->withHeaders(['User-Agent' => 'Mozilla/5.0', 'Accept-Language' => 'ar,en'])->get($url)->body();
        } catch (Throwable) {
            return false;
        }

        if (! preg_match('/APP_INITIALIZATION_STATE=\[\[\[[\d.]+,(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)\]/', $html, $m)) {
            return false;
        }

        return ['lat' => round((float) $m[2], 7), 'lng' => round((float) $m[1], 7), 'address' => self::placeText(rawurldecode($url))];
    }

    /** "/maps/place/Boulevard+World,+Riyadh/@..." gives "Boulevard World, Riyadh". */
    private static function placeText(string $text): ?string
    {
        if (! preg_match('#/maps/place/([^/@?]+)#u', $text, $m)) {
            return null;
        }
        $place = trim(str_replace('+', ' ', $m[1]));

        return $place === '' || preg_match('/^-?\d+\.\d+,\s*-?\d+\.\d+$/', $place) ? null : mb_substr($place, 0, 255);
    }
}
