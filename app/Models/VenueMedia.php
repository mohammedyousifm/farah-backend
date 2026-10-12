<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $venue_id
 * @property string $type
 * @property string|null $path
 * @property string|null $url
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Venue|null $venue
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueMedia newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueMedia newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueMedia query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueMedia whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueMedia whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueMedia wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueMedia whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueMedia whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueMedia whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueMedia whereUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VenueMedia whereVenueId($value)
 *
 * @mixin \Eloquent
 */
class VenueMedia extends Model
{
    protected $table = 'venue_media';

    protected $guarded = ['id'];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function src(): ?string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : $this->url;
    }
}
