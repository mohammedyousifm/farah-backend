<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Working hours of a venue for one weekday (0 = Sunday), in the business timezone.
 * closes_at <= opens_at means the day runs past midnight.
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
