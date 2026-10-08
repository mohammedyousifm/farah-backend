<?php

namespace App\Models;

use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    use Localized;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'lat' => 'float', 'lng' => 'float'];
    }

    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }
}
