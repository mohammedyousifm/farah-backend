<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Working hours of a venue for one weekday (0 = Sunday), in the business timezone.
 *
 * closes_at <= opens_at means the day runs past midnight.
 *
 * @property int $id
 * @property int $venue_id
 * @property int $weekday
 * @property string|null $opens_at
 * @property string|null $closes_at
 * @property bool $is_closed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Venue|null $venue
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueHour newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueHour newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueHour query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueHour whereClosesAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueHour whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueHour whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueHour whereIsClosed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueHour whereOpensAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueHour whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueHour whereVenueId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueHour whereWeekday($value)
 *
 * @mixin \Eloquent
 */
class VenueHour extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_closed' => 'boolean', 'weekday' => 'integer'];
    }

    public function isOpen(): bool
    {
        return ! $this->is_closed && $this->opens_at !== null && $this->closes_at !== null;
    }

    /** "16:00" */
    public function opens(): ?string
    {
        return $this->opens_at ? substr($this->opens_at, 0, 5) : null;
    }

    public function closes(): ?string
    {
        return $this->closes_at ? substr($this->closes_at, 0, 5) : null;
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
