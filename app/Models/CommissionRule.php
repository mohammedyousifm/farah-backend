<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
