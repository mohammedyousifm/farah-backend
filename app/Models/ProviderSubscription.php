<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A provider's subscription to a plan: requested (pending), then activated by an admin once paid.
 * Price and duration are copied from the plan at request time.
 *
 * @property int $id
 * @property int $provider_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property float $price
 * @property int $duration_days
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $payment_reference
 * @property string|null $note
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Provider $provider
 * @property-read Plan $plan
 * @property-read Collection<int, SubscriptionFeature> $features
 * @property-read User|null $requester
 * @property-read User|null $decider
 *
 * @mixin \Eloquent
 */
class ProviderSubscription extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'price' => 'float',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** Active and inside its dates: only such a subscription grants anything. */
    public function isCurrent(): bool
    {
        return $this->status === SubscriptionStatus::Active
            && $this->starts_at?->lte(now()) && $this->ends_at?->gt(now());
    }

    public function isExpired(): bool
    {
        return $this->status === SubscriptionStatus::Active && $this->ends_at?->lte(now());
    }

    public function daysLeft(): int
    {
        return $this->isCurrent() ? (int) ceil(now()->diffInHours($this->ends_at) / 24) : 0;
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(SubscriptionFeature::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
