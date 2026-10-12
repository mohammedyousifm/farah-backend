<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name_ar
 * @property string $name_en
 * @property string $slug
 * @property string|null $icon
 * @property string|null $image
 * @property string|null $description_ar
 * @property string|null $description_en
 * @property bool $requires_license
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Activity> $activities
 * @property-read int|null $activities_count
 * @property-read Collection<int, Category> $categories
 * @property-read int|null $categories_count
 * @property-read Collection<int, SectorField> $fields
 * @property-read int|null $fields_count
 * @property-read Collection<int, Provider> $providers
 * @property-read int|null $providers_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereDescriptionAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereDescriptionEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereNameAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereNameEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereRequiresLicense($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Sector extends Model
{
    use Auditable, Localized;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'requires_license' => 'boolean'];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class)->orderBy('sort_order');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(SectorField::class)->orderBy('sort_order');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(Provider::class)->withPivot('status')->withTimestamps();
    }
}
