<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
