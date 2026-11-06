<?php

namespace App\Http\Middleware;

use App\Services\OrderService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Releases unpaid holds whose payment window ended before the API answers, so booking lists,
 * order pages and availability never show a stale hold, even on a host where the scheduler
 * (farah:expire-orders) is late or not running. One indexed query, at most every few seconds;
 * the paths that take or pay for slots also sweep unconditionally.
 */
class ReleaseExpiredHolds
{
    public function __construct(private OrderService $orders) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Cache::add('farah:holds-sweep', 1, 5)) {
            $this->orders->expireStale();
        }

        return $next($request);
    }
}
