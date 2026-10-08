<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
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
