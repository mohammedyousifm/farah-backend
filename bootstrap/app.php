<?php

use App\Exceptions\BusinessException;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\PreventDuplicateRequests;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\ResolveProvider;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // --- API (owned by the API workstream) ---
        $middleware->append([RequestId::class, SecurityHeaders::class]);
        $middleware->api(prepend: [SetApiLocale::class], append: [PreventDuplicateRequests::class]);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'provider' => ResolveProvider::class,
        ]);
        // Fake payment gateway form posts from a standalone page.
        // The phone scanner page stays open for a whole shift; its long random link is the key.
        $middleware->preventRequestForgery(except: ['payments/fake/*', 'scan/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Expected, user-facing failures (wrong code, slot taken, ...): shown to the user, not logged as errors.
        $exceptions->dontReport(BusinessException::class);

        // Friendly, translated JSON for the common API errors.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('api.unauthenticated'), 'code' => 'unauthenticated'], 401);
            }
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('api.not_found'), 'code' => 'not_found'], 404);
            }
        });
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage() ?: __('api.forbidden'), 'code' => 'forbidden'], 403);
            }
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

                return response()->json(['message' => __('api.too_many_attempts', ['seconds' => $seconds]), 'code' => 'too_many_attempts', 'retry_after' => $seconds], 429, $e->getHeaders());
            }
        });
    })->create();
