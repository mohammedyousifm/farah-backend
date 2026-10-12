<?php

namespace App\Models;

use App\Enums\ProviderStatus;
use App\Enums\VenueStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * An entertainment place (billiards hall, bowling centre, gaming lounge and so on) owned by a provider.
 *
 * @property int $id
 * @property int $provider_id
 * @property int|null $city_id
 * @property string $name_ar
 * @property string|null $name_en
 * @property string $slug
 * @property string|null $summary_ar
 * @property string|null $summary_en
 * @property string|null $description_ar
 * @property string|null $description_en
 * @property string|null $cover_image
 * @property string|null $address
 * @property float|null $lat
 * @property float|null $lng
 * @property string|null $phone
 * @property string $audience
 * @property int|null $min_age
 * @property string $refund_policy
 * @property string|null $refund_policy_note
 * @property int $advance_booking_days
 * @property float $min_price
 * @property bool $is_featured
 * @property VenueStatus $status
 * @property string|null $rejection_reason
 * @property Carbon|null $submitted_at
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $published_at
 * @property int $views_count
 * @property float $rating_avg
 * @property int $rating_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Activity> $activities
 * @property-read int|null $activities_count
 * @property-read Collection<int, Booking> $bookings
 * @property-read int|null $bookings_count
 * @property-read City|null $city
 * @property-read Collection<int, User> $favoritedBy
 * @property-read int|null $favorited_by_count
 * @property-read Collection<int, VenueHour> $hours
 * @property-read int|null $hours_count
 * @property-read Collection<int, VenueMedia> $media
 * @property-read int|null $media_count
 * @property-read Collection<int, Order> $orders
 * @property-read int|null $orders_count
 * @property-read Provider|null $provider
 * @property-read Collection<int, \App\Models\Resource> $resources
 * @property-read int|null $resources_count
 * @property-read User|null $reviewer
 * @property-read Collection<int, Review> $reviews
 * @property-read int|null $reviews_count
 *
 * @method static Builder<static>|Venue newModelQuery()
 * @method static Builder<static>|Venue newQuery()
 * @method static Builder<static>|Venue onlyTrashed()
 * @method static Builder<static>|Venue query()
 * @method static Builder<static>|Venue visible()
 * @method static Builder<static>|Venue whereAddress($value)
 * @method static Builder<static>|Venue whereAdvanceBookingDays($value)
 * @method static Builder<static>|Venue whereAudience($value)
 * @method static Builder<static>|Venue whereCityId($value)
 * @method static Builder<static>|Venue whereCoverImage($value)
 * @method static Builder<static>|Venue whereCreatedAt($value)
 * @method static Builder<static>|Venue whereDeletedAt($value)
 * @method static Builder<static>|Venue whereDescriptionAr($value)
 * @method static Builder<static>|Venue whereDescriptionEn($value)
 * @method static Builder<static>|Venue whereId($value)
 * @method static Builder<static>|Venue whereIsFeatured($value)
 * @method static Builder<static>|Venue whereLat($value)
 * @method static Builder<static>|Venue whereLng($value)
 * @method static Builder<static>|Venue whereMinAge($value)
 * @method static Builder<static>|Venue whereMinPrice($value)
 * @method static Builder<static>|Venue whereNameAr($value)
 * @method static Builder<static>|Venue whereNameEn($value)
 * @method static Builder<static>|Venue wherePhone($value)
 * @method static Builder<static>|Venue whereProviderId($value)
 * @method static Builder<static>|Venue wherePublishedAt($value)
 * @method static Builder<static>|Venue whereRatingAvg($value)
 * @method static Builder<static>|Venue whereRatingCount($value)
 * @method static Builder<static>|Venue whereRefundPolicy($value)
 * @method static Builder<static>|Venue whereRefundPolicyNote($value)
 * @method static Builder<static>|Venue whereRejectionReason($value)
 * @method static Builder<static>|Venue whereReviewedAt($value)
 * @method static Builder<static>|Venue whereReviewedBy($value)
 * @method static Builder<static>|Venue whereSlug($value)
 * @method static Builder<static>|Venue whereStatus($value)
 * @method static Builder<static>|Venue whereSubmittedAt($value)
 * @method static Builder<static>|Venue whereSummaryAr($value)
 * @method static Builder<static>|Venue whereSummaryEn($value)
 * @method static Builder<static>|Venue whereUpdatedAt($value)
 * @method static Builder<static>|Venue whereViewsCount($value)
 * @method static Builder<static>|Venue withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Venue withoutTrashed()
 *
 * @mixin \Eloquent
 */
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
