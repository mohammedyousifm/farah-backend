<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $strategy
 * @property int $weight
 * @property array<array-key, mixed>|null $params
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecommendationRule newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecommendationRule newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecommendationRule query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecommendationRule whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecommendationRule whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecommendationRule whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecommendationRule whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecommendationRule whereParams($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecommendationRule whereStrategy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecommendationRule whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecommendationRule whereWeight($value)
 *
 * @mixin \Eloquent
 */
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
