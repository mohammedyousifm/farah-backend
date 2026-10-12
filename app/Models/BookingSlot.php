<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A held slot of a resource. The unique (resource_id, starts_at) key is what
 * prevents double booking. booking_id null = blocked by the provider.
 *
 * @property int $id
 * @property int $resource_id
 * @property Carbon $starts_at
 * @property int|null $booking_id
 * @property string|null $block_reason
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read Booking|null $booking
 * @property-read \App\Models\Resource $resource
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSlot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSlot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSlot query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSlot whereBlockReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSlot whereBookingId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSlot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSlot whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSlot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSlot whereResourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSlot whereStartsAt($value)
 *
 * @mixin \Eloquent
 */
class BookingSlot extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
