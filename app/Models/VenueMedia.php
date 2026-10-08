<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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
