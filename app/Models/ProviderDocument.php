<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $provider_id
 * @property int|null $sector_id
 * @property DocumentType $type
 * @property string|null $title
 * @property string|null $number
 * @property string $file_path
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property DocumentStatus $status
 * @property string|null $rejection_reason
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $expiry_notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Provider|null $provider
 * @property-read User|null $reviewer
 * @property-read Sector|null $sector
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereExpiryNotifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereFilePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereIssuedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereReviewedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereReviewedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereSectorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProviderDocument whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ProviderDocument extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    /** Documents are personal and legal papers: private disk, never the public one. */
    public const DISK = 'local';

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'issued_at' => 'date',
            'expires_at' => 'date',
            'reviewed_at' => 'datetime',
            'expiry_notified_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function fileUrl(): ?string
    {
        // A signed link that stops working after 15 minutes; there is no permanent public address.
        return $this->file_path ? Storage::disk(self::DISK)->temporaryUrl($this->file_path, now()->addMinutes(15)) : null;
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->expires_at && $this->expires_at->isFuture() && now()->diffInDays($this->expires_at) <= $days;
    }
}
