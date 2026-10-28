<?php

namespace App\Filament\Provider\Support;

use App\Enums\ProviderStatus;
use App\Models\Provider;
use App\Models\ProviderMember;
use Filament\Facades\Filament;

/**
 * Authorization for the provider panel. Combines the member's sub-permissions
 * (ProviderMember::allows, same names as the provider API) with the provider's
 * onboarding state:
 *   - pending / rejected: only the onboarding areas are reachable;
 *   - suspended: everything is read-only;
 *   - approved: normal permission checks.
 */
class ProviderAccess
{
    /**
     * Areas a provider can use before approval. The store ("venues") is one of them: a new provider enters
     * the store's details and activities first, then sends one request for the platform to review.
     */
    public const ONBOARDING_AREAS = ['dashboard', 'profile', 'documents', 'sectors', 'team', 'venues', 'plans'];

    public static function provider(): ?Provider
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Provider ? $tenant : null;
    }

    /** More than one store: only then do tables need a "which store?" column and filter. */
    public static function manyStores(): bool
    {
        return once(fn () => (static::provider()?->venues()->count() ?? 0) > 1);
    }

    /** The signed-in user's active membership of the current provider (cached per request). */
    public static function member(): ?ProviderMember
    {
        $provider = static::provider();
        $userId = auth()->id();

        if (! $provider || ! $userId) {
            return null;
        }

        $key = "provider_member.{$provider->getKey()}.{$userId}";
        $attributes = request()->attributes;

        if (! $attributes->has($key)) {
            $attributes->set($key, ProviderMember::query()
                ->where('provider_id', $provider->getKey())
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->first());
        }

        return $attributes->get($key);
    }

    /** Member sub-permission (owner / manager have all). null = any active member. */
    public static function allows(?string $permission = null): bool
    {
        $member = static::member();

        if (! $member) {
            return false;
        }

        return $permission === null ? (bool) $member->is_active : $member->allows($permission);
    }

    public static function onboarding(): bool
    {
        return in_array(static::provider()?->status, [ProviderStatus::Pending, ProviderStatus::Rejected], true);
    }

    public static function suspended(): bool
    {
        return static::provider()?->status === ProviderStatus::Suspended;
    }

    public static function canView(string $area, ?string $permission = null): bool
    {
        if (! static::allows($permission)) {
            return false;
        }

        return ! static::onboarding() || in_array($area, self::ONBOARDING_AREAS, true);
    }

    /** Write access: view access + the permission, and never while suspended. */
    public static function canManage(string $area, ?string $permission = null): bool
    {
        return static::canView($area, $permission) && ! static::suspended();
    }
}
