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
 * A package providers subscribe to: a price, a duration and a set of features (PlanItem rows).
 *
 * @property int $id
 * @property string $name_ar
 * @property string|null $name_en
 * @property string|null $description_ar
 * @property string|null $description_en
 * @property float $price
 * @property int $duration_days
 * @property bool $is_active
 * @property bool $is_free
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PlanItem> $items
 * @property-read Collection<int, ProviderSubscription> $subscriptions
 *
 * @mixin \Eloquent
 */
class Plan extends Model
{
    use Auditable, Localized;

    protected $guarded = ['id'];

    protected $attributes = ['is_active' => true, 'is_free' => false];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'is_active' => 'boolean',
            'is_free' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // There is one free plan: it costs nothing, and marking another plan free takes the mark off the old one.
        static::saving(function (Plan $plan) {
            if ($plan->is_free) {
                $plan->price = 0;
            }
        });
        static::saved(function (Plan $plan) {
            if ($plan->is_free) {
                static::where('is_free', true)->whereKeyNot($plan->id)->update(['is_free' => false]);
            }
        });
    }

    /** The plan every provider is on while it has no running paid plan (null when the platform has none). */
    public static function free(): ?self
    {
        return static::active()->where('is_free', true)->with('items')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PlanItem::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ProviderSubscription::class);
    }
}
