<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
