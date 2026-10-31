<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A document the platform asked a store for while reviewing it.
 *
 * @property int $id
 * @property int $provider_id
 * @property DocumentType $type
 * @property string|null $note
 * @property int|null $requested_by
 * @property int|null $document_id
 * @property Carbon|null $fulfilled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Provider $provider
 * @property-read ProviderDocument|null $document
 */
class ProviderDocumentRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'fulfilled_at' => 'datetime',
        ];
    }

    /** Not answered yet: nothing uploaded for it, or what was uploaded got rejected. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('fulfilled_at');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ProviderDocument::class);
    }

    /** "License: for the billiards activity" (the note only when there is one). */
    public function label(): string
    {
        return $this->type->getLabel().($this->note ? ': '.$this->note : '');
    }
}
