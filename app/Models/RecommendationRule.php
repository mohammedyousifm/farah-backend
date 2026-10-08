<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class RecommendationRule extends Model
{
    use Auditable;

    public const STRATEGIES = ['same_category', 'same_sector', 'popular_in_city', 'trending', 'top_rated', 'favorites_similar', 'upcoming'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['params' => 'array', 'is_active' => 'boolean'];
    }
}
