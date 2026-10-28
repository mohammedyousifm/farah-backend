<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sector_id
 * @property string $key
 * @property string $label_ar
 * @property string $label_en
 * @property string $type
 * @property array<array-key, mixed>|null $options
 * @property string|null $placeholder_ar
 * @property string|null $placeholder_en
 * @property bool $is_required
 * @property bool $is_filterable
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Sector $sector
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField whereIsFilterable($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField whereIsRequired($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField whereLabelAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField whereLabelEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField whereOptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField wherePlaceholderAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField wherePlaceholderEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField whereSectorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorField whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class SectorField extends Model
{
    use Auditable, Localized;

    public const TYPES = ['text', 'textarea', 'number', 'select', 'multiselect', 'boolean', 'date', 'time', 'url'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }
}
