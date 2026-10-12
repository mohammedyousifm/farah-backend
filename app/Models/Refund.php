<?php

namespace App\Models;

use App\Enums\RefundStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property int $order_id
 * @property int|null $payment_id
 * @property int|null $user_id
 * @property string $type
 * @property float $amount
 * @property float $commission_reversed
 * @property float $provider_debit
 * @property array<array-key, mixed>|null $items
 * @property string|null $reason
 * @property RefundStatus $status
 * @property string|null $admin_notes
 * @property string|null $gateway_refund_id
 * @property int|null $requested_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read Payment|null $payment
 * @property-read User|null $requester
 * @property-read User|null $reviewer
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereAdminNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereCommissionReversed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereGatewayRefundId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereItems($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereProcessedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereProviderDebit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereRequestedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereReviewedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereReviewedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Refund extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount' => 'float',
            'commission_reversed' => 'float',
            'provider_debit' => 'float',
            'items' => 'array',
            'reviewed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
