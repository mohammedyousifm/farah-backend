<?php

namespace App\Models;

use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name_ar
 * @property string $name_en
 * @property string|null $region
 * @property float|null $lat
 * @property float|null $lng
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Venue> $venues
 * @property-read int|null $venues_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereLat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereLng($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereNameAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereNameEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereRegion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
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
