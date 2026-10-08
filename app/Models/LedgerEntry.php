<?php

namespace App\Models;

use App\Enums\LedgerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only financial record. Amounts are signed (+credit / -debit).
 * Entries are never edited or deleted; corrections are new entries.
 * The only mutable column is settlement_id (linking an entry to a payout).
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => LedgerType::class,
            'amount' => 'float',
            'available_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (LedgerEntry $entry) {
            if (array_diff(array_keys($entry->getDirty()), ['settlement_id']) !== []) {
                throw new LogicException('Ledger entries are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Ledger entries are immutable.'));
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
