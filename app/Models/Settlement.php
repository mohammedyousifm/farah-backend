<?php

namespace App\Models;

use App\Enums\SettlementStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $number
 * @property int $provider_id
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property float $gross_amount
 * @property float $commission_amount
 * @property float $refunds_amount
 * @property float $adjustments_amount
 * @property float $net_amount
 * @property SettlementStatus $status
 * @property array<array-key, mixed>|null $bank_snapshot
 * @property string|null $payment_reference
 * @property string|null $receipt_path
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $paid_by
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $approver
 * @property-read User|null $creator
 * @property-read Collection<int, LedgerEntry> $entries
 * @property-read int|null $entries_count
 * @property-read User|null $payer
 * @property-read Provider|null $provider
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereAdjustmentsAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereApprovedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereApprovedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereBankSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereCommissionAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereGrossAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereNetAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement wherePaidAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement wherePaidBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement wherePaymentReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement wherePeriodFrom($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement wherePeriodTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereReceiptPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereRefundsAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Settlement whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Settlement extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    /** Bank transfer receipts are financial papers: private disk, short-lived signed links. */
    public const RECEIPT_DISK = 'local';

    protected function casts(): array
    {
        return [
            'status' => SettlementStatus::class,
            'period_from' => 'date',
            'period_to' => 'date',
            'gross_amount' => 'float',
            'commission_amount' => 'float',
            'refunds_amount' => 'float',
            'adjustments_amount' => 'float',
            'net_amount' => 'float',
            'bank_snapshot' => 'array',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function receiptUrl(): ?string
    {
        return $this->receipt_path ? Storage::disk(self::RECEIPT_DISK)->temporaryUrl($this->receipt_path, now()->addMinutes(15)) : null;
    }
}
