<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One resource reserved for a continuous run of slots. Belongs to the order that pays for it.
 *
 * @property int $id
 * @property string $code
 * @property int $order_id
 * @property int|null $user_id
 * @property int $provider_id
 * @property int $venue_id
 * @property int $activity_id
 * @property int $resource_id
 * @property string $name
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $slot_minutes
 * @property int $slots
 * @property float $unit_price
 * @property float $total
 * @property BookingStatus $status
 * @property Carbon|null $checked_in_at
 * @property int|null $checked_in_by
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Activity $activity
 * @property-read User|null $checkedInBy
 * @property-read Collection<int, BookingSlot> $heldSlots
 * @property-read int|null $held_slots_count
 * @property-read Order $order
 * @property-read Provider|null $provider
 * @property-read \App\Models\Resource $resource
 * @property-read User|null $user
 * @property-read Venue|null $venue
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereActivityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereCancelledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereCheckedInAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereCheckedInBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereEndsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereResourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereSlotMinutes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereSlots($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereStartsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereUnitPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereVenueId($value)
 *
 * @mixin \Eloquent
 */
class Booking extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'unit_price' => 'float',
            'total' => 'float',
            'slots' => 'integer',
            'slot_minutes' => 'integer',
        ];
    }

    /** Still counts as a reservation (not cancelled or expired). */
    public function isActive(): bool
    {
        return in_array($this->status->value, BookingStatus::HOLDING, true);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class)->withTrashed();
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function heldSlots(): HasMany
    {
        return $this->hasMany(BookingSlot::class);
    }
}
