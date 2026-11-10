<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side limits on an admin session (AUTH-ADMIN-004 / 003), enforced whatever the browser cookie says:
 *  - idle: signed out after config('farah.admin_session.idle_minutes') without a real action;
 *  - absolute: signed out config('farah.admin_session.absolute_minutes') after sign-in, active or not.
 *
 * Background refreshes do NOT count as activity: Livewire requests whose every call is a wire:poll
 * (dashboard numbers, the notification bell) or a lazy load, with no field changes. So a dashboard left
 * open on an unattended screen still times out. Real clicks, form changes and page loads do count.
 *
 * Runs on page loads and, as persistent panel middleware, on every Livewire request of the admin panel.
 * On a Livewire request the redirect to the sign-in page is followed by the browser (Livewire reloads to it).
 */
class EnforceSessionTimeout
{
    public const STARTED = 'auth.session_started_at';

    public const LAST_ACTIVITY = 'auth.last_activity_at';

    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        $auth = Auth::guard($guard);
        if (! $request->hasSession() || ! $auth->check()) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp();
        $started = (int) $session->get(self::STARTED, 0);
        $last = (int) $session->get(self::LAST_ACTIVITY, 0);

        if ($started === 0) {
            // First request of this sign-in (or a session from before this check existed).
            $session->put(self::STARTED, $now);
            $session->put(self::LAST_ACTIVITY, $now);
        } elseif ($now - $last > self::idleSeconds() || $now - $started > self::absoluteSeconds()) {
            $auth->logout();
            $session->invalidate();
            $session->regenerateToken();

            Notification::make()->warning()->title(__('admin.session.expired'))->persistent()->send();

            return redirect()->to(Filament::getPanel('admin')->getLoginUrl());
        }

        $response = $next($request);

        if (! self::isBackground($request) && $auth->check()) {
            $session->put(self::LAST_ACTIVITY, $now);
        }

        return $response;
    }

    public static function idleSeconds(): int
    {
        return max(1, (int) config('farah.admin_session.idle_minutes', 30)) * 60;
    }

    public static function absoluteSeconds(): int
    {
        return max(1, (int) config('farah.admin_session.absolute_minutes', 720)) * 60;
    }

    /** A Livewire request that only polls or lazy-loads, without any change made by the person. */
    public static function isBackground(Request $request): bool
    {
        if (! $request->hasHeader('X-Livewire') || ! $request->isMethod('POST')) {
            return false;
        }

        $components = $request->input('components');
        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            if (! is_array($component) || ! empty($component['updates'])) {
                return false;
            }
            $calls = $component['calls'] ?? [];
            if (! is_array($calls) || $calls === []) {
                return false;
            }
            foreach ($calls as $call) {
                $passive = (($call['metadata']['type'] ?? null) === 'poll') || (($call['method'] ?? null) === '__lazyLoad');
                if (! $passive) {
                    return false;
                }
            }
        }

        return true;
    }
}
