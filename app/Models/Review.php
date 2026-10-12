<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $venue_id
 * @property int $provider_id
 * @property int|null $order_id
 * @property int $rating
 * @property string|null $comment
 * @property string $status
 * @property string|null $provider_reply
 * @property Carbon|null $replied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 * @property-read Provider|null $provider
 * @property-read User|null $user
 * @property-read Venue|null $venue
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereProviderReply($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereRepliedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereVenueId($value)
 *
 * @mixin \Eloquent
 */
class Review extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['replied_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saved(fn (Review $r) => $r->refreshAggregates());
        static::deleted(fn (Review $r) => $r->refreshAggregates());
    }

    /** Keep venue/provider rating_avg + rating_count in sync with published reviews. */
    public function refreshAggregates(): void
    {
        foreach ([$this->venue, $this->provider] as $target) {
            if (! $target) {
                continue;
            }
            $stats = $target->reviews()->where('status', 'published')
                ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as cnt')->toBase()->first();
            $target->forceFill([
                'rating_avg' => round((float) $stats->avg_rating, 2),
                'rating_count' => (int) $stats->cnt,
            ])->saveQuietly();
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class)->withTrashed();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
