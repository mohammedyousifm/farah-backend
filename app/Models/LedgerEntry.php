<?php

namespace App\Models;

use App\Enums\LedgerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only financial record. Amounts are signed (+credit / -debit).
 *
 * Entries are never edited or deleted; corrections are new entries.
 * The only mutable column is settlement_id (linking an entry to a payout).
 *
 * @property int $id
 * @property string $account
 * @property int|null $provider_id
 * @property int|null $order_id
 * @property int|null $refund_id
 * @property int|null $settlement_id
 * @property LedgerType $type
 * @property float $amount
 * @property string|null $description
 * @property Carbon|null $available_at
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read User|null $creator
 * @property-read Order|null $order
 * @property-read Provider|null $provider
 * @property-read Refund|null $refund
 * @property-read Settlement|null $settlement
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereAccount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereAvailableAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereRefundId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereSettlementId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereType($value)
 *
 * @mixin \Eloquent
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
