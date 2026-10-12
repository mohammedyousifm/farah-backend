<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * A double tap, an impatient second tap or a network retry sends the same action twice. The second
 * identical request (same person, same address, same content) within a few seconds does not run
 * again: it waits for the first one and gets the very same answer back.
 */
class PreventDuplicateRequests
{
    /** Harmless to repeat, or legitimately repeated quickly (favourite on / off / on). */
    private const SKIP = ['api/v1/track', 'api/v1/favorites/*', 'api/v1/notifications/*', 'api/v1/me/device-tokens', 'api/v1/auth/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $seconds = (int) config('farah.api.dedupe_seconds');

        if ($seconds <= 0 || $request->isMethodSafe() || $request->is(...self::SKIP)) {
            return $next($request);
        }

        $key = 'dup:'.sha1(implode('|', [
            $request->bearerToken() ?: $request->ip(),
            $request->method(),
            $request->path(),
            $this->fingerprint($request),
        ]));

        $lock = Cache::lock($key.':lock', 30);

        try {
            // Wait for the twin that is still running instead of racing it.
            $lock->block(15);
        } catch (LockTimeoutException) {
            return response()->json(['message' => __('api.duplicate_request'), 'code' => 'duplicate_request'], 409);
        }

        try {
            if ($seen = Cache::get($key)) {
                return response($seen['content'], $seen['status'], ['Content-Type' => 'application/json', 'X-Duplicate-Request' => 'replayed']);
            }

            $response = $next($request);

            // Only successes are remembered: after an error the person corrects and tries again.
            if ($response->isSuccessful() && is_string($response->getContent())) {
                Cache::put($key, ['status' => $response->getStatusCode(), 'content' => $response->getContent()], $seconds);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function fingerprint(Request $request): string
    {
        $files = collect($request->allFiles())->flatten()
            ->map(fn ($file) => $file->getClientOriginalName().':'.$file->getSize())->implode(',');

        return $request->getContent() !== '' ? $request->getContent() : json_encode($request->except(array_keys($request->allFiles()))).$files;
    }
}
