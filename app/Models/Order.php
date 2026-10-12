<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property string|null $invoice_number
 * @property Carbon|null $invoice_issued_at
 * @property int|null $user_id
 * @property int $provider_id
 * @property int $venue_id
 * @property int|null $payment_id
 * @property string $channel
 * @property OrderStatus $status
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string|null $customer_email
 * @property float $subtotal
 * @property float $discount
 * @property float $vat_amount
 * @property float $total
 * @property float $refunded_amount
 * @property int|null $commission_rule_id
 * @property float $commission_rate
 * @property float $commission_fixed
 * @property float $commission_amount
 * @property float $provider_net
 * @property string $currency
 * @property string|null $offline_payment_method
 * @property string|null $notes
 * @property string|null $cancel_reason
 * @property int|null $created_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $review_prompted_at
 * @property string|null $accounting_ref
 * @property Carbon|null $accounting_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Booking> $bookings
 * @property-read int|null $bookings_count
 * @property-read CommissionRule|null $commissionRule
 * @property-read User|null $creator
 * @property-read Collection<int, Dispute> $disputes
 * @property-read int|null $disputes_count
 * @property-read Collection<int, LedgerEntry> $ledgerEntries
 * @property-read int|null $ledger_entries_count
 * @property-read Payment|null $payment
 * @property-read Provider|null $provider
 * @property-read Collection<int, Refund> $refunds
 * @property-read int|null $refunds_count
 * @property-read User|null $user
 * @property-read Venue|null $venue
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereAccountingRef($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereAccountingSyncedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCancelReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCancelledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCommissionAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCommissionFixed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCommissionRate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCommissionRuleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereConfirmedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCustomerEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCustomerName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCustomerPhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereDiscount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereOfflinePaymentMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereProviderNet($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereRefundedAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereReviewPromptedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereVatAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereVenueId($value)
 *
 * @mixin \Eloquent
 */
class Order extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'float',
            'discount' => 'float',
            'vat_amount' => 'float',
            'total' => 'float',
            'refunded_amount' => 'float',
            'commission_rate' => 'float',
            'commission_fixed' => 'float',
            'commission_amount' => 'float',
            'provider_net' => 'float',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'invoice_issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'review_prompted_at' => 'datetime',
            'accounting_synced_at' => 'datetime',
        ];
    }

    /** Statuses in which money has been collected for the order. */
    public const PAID_STATUSES = ['confirmed', 'completed', 'partially_refunded', 'refunded'];

    public function isPaid(): bool
    {
        return in_array($this->status->value, self::PAID_STATUSES, true);
    }

    public function refundableAmount(): float
    {
        return round($this->total - $this->refunded_amount, 2);
    }

    public function customerDisplayName(): string
    {
        return $this->user?->name ?? $this->customer_name ?? '-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class)->withTrashed();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class)->orderBy('starts_at');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
