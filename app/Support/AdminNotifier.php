<?php

namespace App\Support;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Notifications\SystemNotification;
use Throwable;

/**
 * Tells the right admins that something is waiting for them (a provider to approve, a refund to review,
 * a complaint to answer). It appears in the bell of the admin dashboard.
 */
class AdminNotifier
{
    /**
     * @param  array<int, string>  $roles  roles concerned, besides super admins who see everything
     */
    public static function send(array $roles, string $key, array $params = [], array $data = []): void
    {
        try {
            User::query()
                ->where('type', UserType::Admin)
                ->where('status', UserStatus::Active)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', [...$roles, 'super_admin']))
                ->get()
                ->each(fn (User $admin) => SystemNotification::send($admin, $key, $params, $data));
        } catch (Throwable $e) {
            // Telling the team must never stop the customer's or provider's own action.
            report($e);
        }
    }
}
