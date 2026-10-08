<?php

namespace App\Models;

use App\Enums\ProviderStatus;
use App\Enums\VenueStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** An entertainment place (billiards hall, bowling centre, gaming lounge, ...) owned by a provider. */
class Venue extends Model
{
    use Auditable, Localized, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => VenueStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
            'is_featured' => 'boolean',
            'min_price' => 'float',
            'rating_avg' => 'float',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    /** Visible to customers: published and the provider is approved. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', VenueStatus::Published)
            ->whereHas('provider', fn ($q) => $q->where('status', ProviderStatus::Approved));
    }

    public function isBookable(): bool
    {
        return $this->status === VenueStatus::Published
            && $this->provider?->status === ProviderStatus::Approved;
    }

    public function coverUrl(): ?string
    {
        return $this->cover_image ? Storage::disk('public')->url($this->cover_image) : null;
    }

    /** Lowest hourly price among active activities. */
    public function refreshMinPrice(): void
    {
        $this->forceFill(['min_price' => (float) $this->activities()->where('is_active', true)->min('price_per_hour')])->saveQuietly();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function media(): HasMany
    {
        return $this->hasMany(VenueMedia::class)->orderBy('sort_order');
    }

    public function hours(): HasMany
    {
        return $this->hasMany(VenueHour::class)->orderBy('weekday');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->orderBy('sort_order');
    }

    public function resources(): HasManyThrough
    {
        return $this->hasManyThrough(Resource::class, Activity::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withTimestamps();
    }
}
