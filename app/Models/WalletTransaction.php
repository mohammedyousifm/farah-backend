<?php

namespace App\Models;

use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One movement on a customer's wallet. Rows are never edited or deleted.
 *
 * @property int $id
 * @property int $user_id
 * @property WalletTransactionType $type
 * @property float $amount
 * @property float $balance_after
 * @property int|null $order_id
 * @property int|null $refund_id
 * @property int|null $payment_id
 * @property int|null $complaint_id
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read User $user
 * @property-read Order|null $order
 * @property-read Refund|null $refund
 * @property-read Complaint|null $complaint
 * @property-read User|null $creator
 *
 * @mixin \Eloquent
 */
class WalletTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'amount' => 'float',
            'balance_after' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
