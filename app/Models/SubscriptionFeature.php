<?php

namespace App\Models;

use App\Enums\PlanFeature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A feature a subscription grants, with its own dates: it may end before the subscription does.
 *
 * @property int $id
 * @property int $provider_subscription_id
 * @property int $provider_id
 * @property PlanFeature $feature
 * @property int|null $value
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property-read ProviderSubscription $subscription
 *
 * @mixin \Eloquent
 */
class SubscriptionFeature extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'feature' => PlanFeature::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function isRunning(): bool
    {
        return $this->starts_at->lte(now()) && $this->ends_at->gt(now());
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ProviderSubscription::class, 'provider_subscription_id');
    }
}
