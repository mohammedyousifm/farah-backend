<?php

namespace App\Models;

use App\Enums\PlanFeature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One feature of a plan, with its limit (for limit features) and how many days it lasts
 * inside the subscription (null = as long as the subscription).
 *
 * @property int $id
 * @property int $plan_id
 * @property PlanFeature $feature
 * @property int|null $value
 * @property int|null $days
 * @property-read Plan $plan
 *
 * @mixin \Eloquent
 */
class PlanItem extends Model
{
    public $timestamps = false;

    protected $table = 'plan_features';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['feature' => PlanFeature::class];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
