<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderMember extends Model
{
    /** Sub-permissions a provider owner/manager can grant to staff. */
    public const PERMISSIONS = [
        'venues.manage', 'orders.view', 'orders.manage', 'orders.offline', 'bookings.checkin',
        'finance.view', 'team.manage', 'reviews.reply', 'profile.manage',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_active' => 'boolean'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allows(string $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return in_array($this->role, ['owner', 'manager'], true)
            || in_array($permission, $this->permissions ?? [], true);
    }
}
