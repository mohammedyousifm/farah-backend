<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Locale from Accept-Language (ar | en), falling back to the user's saved locale, then Arabic. */
class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = strtolower(substr((string) $request->header('Accept-Language'), 0, 2));
        // Runs before auth:sanctum, so the bearer token is read through the sanctum guard here.
        $locale = in_array($header, ['ar', 'en'], true) ? $header : ($request->user('sanctum')?->locale ?? 'ar');

        app()->setLocale(in_array($locale, ['ar', 'en'], true) ? $locale : 'ar');

        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }
}
