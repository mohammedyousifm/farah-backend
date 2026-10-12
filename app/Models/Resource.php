<?php

namespace App\Models;

use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The bookable unit of an activity: one table, lane, room, track or court.
 *
 * @property int $id
 * @property int $activity_id
 * @property string $name_ar
 * @property string|null $name_en
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Activity $activity
 * @property-read Collection<int, Booking> $bookings
 * @property-read int|null $bookings_count
 * @property-read Collection<int, BookingSlot> $slots
 * @property-read int|null $slots_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereActivityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereNameAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereNameEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Resource extends Model
{
    use Localized;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(BookingSlot::class);
    }
}
