<?php

namespace App\Models;

use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Something to play in a venue (billiards, bowling, ...), priced per hour and split into resources.
 *
 * @property int $id
 * @property int $venue_id
 * @property int $sector_id
 * @property int|null $category_id
 * @property string $name_ar
 * @property string|null $name_en
 * @property string|null $description_ar
 * @property string|null $description_en
 * @property float $price_per_hour
 * @property int $slot_minutes
 * @property int $min_slots
 * @property int $max_slots
 * @property int|null $capacity
 * @property bool $is_active
 * @property string $review_status
 * @property string|null $review_note
 * @property Carbon|null $reviewed_at
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Booking> $bookings
 * @property-read int|null $bookings_count
 * @property-read Category|null $category
 * @property-read Collection<int, \App\Models\Resource> $resources
 * @property-read int|null $resources_count
 * @property-read Sector $sector
 * @property-read Venue|null $venue
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereDescriptionAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereDescriptionEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereMaxSlots($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereMinSlots($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereNameAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereNameEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity wherePricePerHour($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereReviewNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereReviewStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereReviewedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereSectorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereSlotMinutes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Activity whereVenueId($value)
 *
 * @mixin \Eloquent
 */
class Activity extends Model
{
    use Localized;

    protected $guarded = ['id'];

    /** Review of an activity added to a live store. Only an approved activity can be active (bookable). */
    public const APPROVED = 'approved';

    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    public function isApproved(): bool
    {
        return ($this->review_status ?? self::APPROVED) === self::APPROVED;
    }

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'price_per_hour' => 'float',
            'slot_minutes' => 'integer',
            'min_slots' => 'integer',
            'max_slots' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (Activity $a) => $a->venue?->refreshMinPrice());
        static::deleted(fn (Activity $a) => $a->venue?->refreshMinPrice());
    }

    /** Price of one slot (prices are VAT-inclusive). */
    public function slotPrice(): float
    {
        return round($this->price_per_hour * $this->slot_minutes / 60, 2);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class)->withTrashed();
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class)->orderBy('sort_order')->orderBy('id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
