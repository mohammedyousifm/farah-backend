<?php

namespace App\Models;

use App\Enums\InternalRating;
use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Models\Concerns\Auditable;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Provider extends Model implements HasAvatar, HasName
{
    use Auditable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ProviderType::class,
            'status' => ProviderStatus::class,
            'internal_rating' => InternalRating::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'wathq_verified_at' => 'datetime',
            'wathq_payload' => 'array',
            'rating_avg' => 'float',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }

    /** Tenant name in the provider panel's tenant switcher. */
    public function getFilamentName(): string
    {
        return $this->displayName();
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->logoUrl();
    }

    public function isApproved(): bool
    {
        return $this->status === ProviderStatus::Approved;
    }

    public function logoUrl(): ?string
    {
        return $this->logo ? Storage::disk('public')->url($this->logo) : null;
    }

    public function coverUrl(): ?string
    {
        return $this->cover ? Storage::disk('public')->url($this->cover) : null;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class)->withPivot('status')->withTimestamps();
    }

    public function approvedSectors(): BelongsToMany
    {
        return $this->sectors()->wherePivot('status', 'approved');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProviderDocument::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProviderMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'provider_members')
            ->withPivot(['role', 'permissions', 'is_active'])
            ->withTimestamps();
    }

    public function sectorRequests(): HasMany
    {
        return $this->hasMany(SectorRequest::class);
    }

    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
