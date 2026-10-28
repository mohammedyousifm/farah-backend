<?php

namespace App\Http\Middleware;

use App\Enums\ProviderStatus;
use App\Models\ProviderMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the provider the user is acting for (header X-Provider-Id, else their
 * first active membership) and optionally checks a member sub-permission:
 *   ->middleware('provider')              any active member
 *   ->middleware('provider:orders.view')  member with that permission
 *
 * The resolved Provider is bound as request attribute "provider" and
 * the membership as "provider_member".
 */
class ResolveProvider
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $query = ProviderMember::with('provider')
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->whereHas('provider');

        if ($id = $request->header('X-Provider-Id')) {
            $query->where('provider_id', (int) $id);
        }

        $member = $query->orderBy('id')->first();

        if (! $member) {
            return response()->json(['message' => __('api.providers.not_member'), 'code' => 'not_provider_member'], 403);
        }

        if ($permission && ! $member->allows($permission)) {
            return response()->json(['message' => __('api.forbidden'), 'code' => 'missing_permission', 'permission' => $permission], 403);
        }

        // A suspended provider is read-only (same rule as the provider panel).
        if ($member->provider->status === ProviderStatus::Suspended && ! $request->isMethodSafe()) {
            return response()->json(['message' => __('api.providers.suspended'), 'code' => 'provider_suspended'], 403);
        }

        $request->attributes->set('provider', $member->provider);
        $request->attributes->set('provider_member', $member);

        return $next($request);
    }
}
