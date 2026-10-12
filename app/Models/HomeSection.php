<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $title_ar
 * @property string|null $title_en
 * @property string $type
 * @property string $layout
 * @property string $source
 * @property array<array-key, mixed>|null $source_params
 * @property int $max_items
 * @property bool $is_active
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, HomeSectionItem> $items
 * @property-read int|null $items_count
 *
 * @method static Builder<static>|HomeSection live()
 * @method static Builder<static>|HomeSection newModelQuery()
 * @method static Builder<static>|HomeSection newQuery()
 * @method static Builder<static>|HomeSection query()
 * @method static Builder<static>|HomeSection whereCreatedAt($value)
 * @method static Builder<static>|HomeSection whereEndsAt($value)
 * @method static Builder<static>|HomeSection whereId($value)
 * @method static Builder<static>|HomeSection whereIsActive($value)
 * @method static Builder<static>|HomeSection whereLayout($value)
 * @method static Builder<static>|HomeSection whereMaxItems($value)
 * @method static Builder<static>|HomeSection whereSortOrder($value)
 * @method static Builder<static>|HomeSection whereSource($value)
 * @method static Builder<static>|HomeSection whereSourceParams($value)
 * @method static Builder<static>|HomeSection whereStartsAt($value)
 * @method static Builder<static>|HomeSection whereTitleAr($value)
 * @method static Builder<static>|HomeSection whereTitleEn($value)
 * @method static Builder<static>|HomeSection whereType($value)
 * @method static Builder<static>|HomeSection whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class HomeSection extends Model
{
    use Auditable, Localized;

    public const TYPES = ['banner', 'venues', 'categories', 'sectors', 'providers'];

    public const LAYOUTS = ['hero', 'carousel', 'grid', 'list'];

    public const SOURCES = ['manual', 'featured', 'latest', 'upcoming', 'top_rated', 'popular', 'category', 'sector', 'recommended'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source_params' => 'array',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** Active and inside its schedule window. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort_order');
    }

    public function items(): HasMany
    {
        return $this->hasMany(HomeSectionItem::class)->orderBy('sort_order');
    }
}
