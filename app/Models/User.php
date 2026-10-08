<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'birth_date' => 'date',
            'password' => 'hashed',
            'type' => UserType::class,
            'status' => UserStatus::class,
            'marketing_consent' => 'boolean',
            'tracking_consent' => 'boolean',
        ];
    }

    /**
     * admin panel: active admins only. provider panel: active non-admin users with at
     * least one active membership of a (non-deleted) provider. Admins reach the
     * provider panel only through impersonation (they are then logged in as the owner).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->status !== UserStatus::Active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->type === UserType::Admin,
            'provider' => $this->type !== UserType::Admin && $this->activeProviders()->exists(),
            default => false,
        };
    }

    /** Providers this user is an active member of (the tenants of the provider panel). */
    public function activeProviders(): BelongsToMany
    {
        return $this->providers()->wherePivot('is_active', true);
    }

    public function getTenants(Panel $panel): array|Collection
    {
        return $this->activeProviders()->orderBy('providers.id')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Provider && $this->activeProviders()->whereKey($tenant->getKey())->exists();
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->activeProviders()->orderBy('providers.id')->first();
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** Provider account owned by this user. */
    public function ownedProvider(): HasOne
    {
        return $this->hasOne(Provider::class, 'owner_id');
    }

    /** Providers this user works for (owner or team member). */
    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(Provider::class, 'provider_members')
            ->withPivot(['role', 'permissions', 'is_active'])
            ->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function favorites(): BelongsToMany
    {
        return $this->belongsToMany(Venue::class, 'favorites')->withTimestamps();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }
}
