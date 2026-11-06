<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response: no framing by other sites (clickjacking),
 * no MIME sniffing, a short referrer, HTTPS pinned once the site is served over HTTPS, and
 * no "X-Powered-By" version banner.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $panel = $request->is('admin*', 'provider*', 'livewire*');
        $nonce = $panel ? null : Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', self::contentPolicy($nonce));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(self)');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // No other site may script a window it opened onto ours, and legacy plugins get no policy file.
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Dashboards, the API and the phone scanner are not for search engines.
        if ($request->is('admin*', 'provider*', 'api/*', 'scan/*', 'payments/*')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        // Anything answered to a signed-in person is personal: never kept by a shared cache or proxy.
        if ($request->bearerToken() || $request->user()) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        $response->headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }

    /** Public pages run only our own files and scripts carrying this request's nonce. Filament needs inline code and eval. */
    private static function contentPolicy(?string $nonce): string
    {
        $scripts = $nonce ? "script-src 'self' 'nonce-{$nonce}'" : "script-src 'self' 'unsafe-inline' 'unsafe-eval'";

        return implode('; ', [
            "default-src 'self'",
            $scripts,
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob: https:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
