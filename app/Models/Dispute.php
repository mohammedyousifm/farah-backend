<?php

namespace App\Models;

use App\Enums\DisputeStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property int $order_id
 * @property int $provider_id
 * @property int|null $user_id
 * @property int|null $refund_id
 * @property string $reason
 * @property string|null $details
 * @property float|null $amount
 * @property DisputeStatus $status
 * @property string|null $resolution
 * @property int|null $assigned_to
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $assignee
 * @property-read Order $order
 * @property-read Provider|null $provider
 * @property-read Refund|null $refund
 * @property-read User|null $resolver
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereAssignedTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereDetails($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereRefundId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereResolution($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereResolvedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereResolvedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Dispute extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => DisputeStatus::class, 'amount' => 'float', 'resolved_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
