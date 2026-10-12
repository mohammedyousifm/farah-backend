<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $provider_id
 * @property int $user_id
 * @property string $role
 * @property array<array-key, mixed>|null $permissions
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Provider|null $provider
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderMember newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderMember newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderMember query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderMember whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderMember whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderMember whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderMember wherePermissions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderMember whereProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderMember whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderMember whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderMember whereUserId($value)
 *
 * @mixin \Eloquent
 */
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
