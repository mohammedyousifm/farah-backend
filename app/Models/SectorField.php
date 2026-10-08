<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** Laravel validation rules for a value submitted for this field. */
    public function validationRules(): array
    {
        $rules = [$this->is_required ? 'required' : 'nullable'];
        $values = collect($this->options ?? [])->pluck('value')->all();

        return array_merge($rules, match ($this->type) {
            'number' => ['numeric'],
            'boolean' => ['boolean'],
            'date' => ['date'],
            'time' => ['date_format:H:i'],
            'url' => ['url'],
            'select' => ['in:'.implode(',', $values)],
            'multiselect' => ['array'],
            'textarea' => ['string', 'max:5000'],
            default => ['string', 'max:255'],
        });
    }
}
