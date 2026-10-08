<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Admin panel language: Arabic (RTL) by default, switchable to English, kept in the session. */
class SetAdminLocale
{
    public const LOCALES = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('admin_locale', 'ar');
        app()->setLocale(in_array($locale, self::LOCALES, true) ? $locale : 'ar');

        return $next($request);
    }
}
