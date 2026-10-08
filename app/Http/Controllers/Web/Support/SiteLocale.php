<?php

namespace App\Http\Controllers\Web\Support;

use App\Http\Middleware\SetAdminLocale;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Session\Store;

/**
 * Locale of the public website. Arabic by default; ?lang=en|ar switches and is
 * remembered in the same session key as the dashboards (admin_locale), so the
 * provider login page follows the visitor's choice.
 */
class SiteLocale
{
    public const DEFAULT = 'ar';

    /** For public pages running inside the web middleware group (session started). */
    public static function apply(Request $request): string
    {
        $lang = $request->query('lang');
        if (self::valid($lang)) {
            $request->session()->put('admin_locale', $lang);
        }

        $locale = $request->session()->get('admin_locale');

        return self::set(self::valid($locale) ? $locale : self::DEFAULT);
    }

    /**
     * For error pages (404 / 500 / 503). They often render outside the web
     * middleware group (no route matched, so the session was never started),
     * so the session is read straight from the session cookie. Never throws.
     */
    public static function forErrorPage(): string
    {
        try {
            $request = request();

            $lang = $request->query('lang');
            if (self::valid($lang)) {
                return self::set($lang);
            }

            $session = app('session')->driver();
            if ($session->isStarted()) {
                $locale = $session->get('admin_locale');

                return self::set(self::valid($locale) ? $locale : self::DEFAULT);
            }

            $name = config('session.cookie');
            $cookie = $request->cookies->get($name);
            if (is_string($cookie) && $cookie !== '') {
                $encrypter = app(Encrypter::class);
                $id = CookieValuePrefix::validate($name, $encrypter->decrypt($cookie, false), $encrypter->getAllKeys());

                if (is_string($id) && $id !== '') {
                    $store = new Store($name, $session->getHandler(), $id, config('session.serialization', 'php'));
                    $store->start();
                    $locale = $store->get('admin_locale');

                    return self::set(self::valid($locale) ? $locale : self::DEFAULT);
                }
            }
        } catch (\Throwable) {
            // Fall through to the default: an error page must always render.
        }

        return self::set(app()->getLocale() === 'en' ? 'en' : self::DEFAULT);
    }

    public static function valid(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, SetAdminLocale::LOCALES, true);
    }

    public static function other(): string
    {
        return app()->getLocale() === 'ar' ? 'en' : 'ar';
    }

    private static function set(string $locale): string
    {
        app()->setLocale($locale);

        return $locale;
    }
}
