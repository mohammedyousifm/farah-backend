<?php

namespace App\Models;

use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Something to play in a venue (billiards, bowling, ...), priced per hour and split into resources. */
class Activity extends Model
{
    use Localized;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
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
