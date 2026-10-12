<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $scope
 * @property int|null $scope_id
 * @property string $type
 * @property float $rate
 * @property float $fixed_amount
 * @property int $version
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property bool $is_active
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule target()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereEffectiveFrom($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereEffectiveTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereFixedAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereRate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereScope($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereScopeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommissionRule whereVersion($value)
 *
 * @mixin \Eloquent
 */
class CommissionRule extends Model
{
    use Auditable;

    public const SCOPES = ['global', 'sector', 'category', 'provider'];

    public const TYPES = ['percentage', 'fixed', 'percentage_plus_fixed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'fixed_amount' => 'float',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function calculate(float $amount): float
    {
        $value = match ($this->type) {
            'fixed' => $this->fixed_amount,
            'percentage_plus_fixed' => $amount * $this->rate / 100 + $this->fixed_amount,
            default => $amount * $this->rate / 100,
        };

        return round(min($value, $amount), 2);
    }

    public function scopeTarget(): string
    {
        return match ($this->scope) {
            'sector' => __('admin.scopes.sector').': '.Sector::find($this->scope_id)?->tr('name'),
            'category' => __('admin.scopes.category').': '.Category::find($this->scope_id)?->tr('name'),
            'provider' => __('admin.scopes.provider').': '.Provider::find($this->scope_id)?->displayName(),
            default => __('admin.scopes.global'),
        };
    }
}
