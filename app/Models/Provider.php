<?php

namespace App\Models;

use App\Enums\InternalRating;
use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Models\Concerns\Auditable;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $owner_id
 * @property ProviderType $type
 * @property string $name_ar
 * @property string|null $name_en
 * @property string $slug
 * @property string|null $bio_ar
 * @property string|null $bio_en
 * @property string|null $logo
 * @property string|null $cover
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property int|null $city_id
 * @property string|null $address
 * @property float|null $lat
 * @property float|null $lng
 * @property string|null $national_id
 * @property string|null $cr_number
 * @property string|null $legal_name
 * @property string|null $vat_number
 * @property string|null $bank_name
 * @property string|null $iban
 * @property string|null $account_holder
 * @property ProviderStatus $status
 * @property string|null $rejection_reason
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property InternalRating|null $internal_rating
 * @property string|null $internal_notes
 * @property float $rating_avg
 * @property int $rating_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Sector> $approvedSectors
 * @property-read int|null $approved_sectors_count
 * @property-read User|null $approver
 * @property-read City|null $city
 * @property-read Collection<int, ProviderDocument> $documents
 * @property-read int|null $documents_count
 * @property-read Collection<int, LedgerEntry> $ledgerEntries
 * @property-read int|null $ledger_entries_count
 * @property-read Collection<int, ProviderMember> $members
 * @property-read int|null $members_count
 * @property-read Collection<int, Order> $orders
 * @property-read int|null $orders_count
 * @property-read User|null $owner
 * @property-read Collection<int, Review> $reviews
 * @property-read int|null $reviews_count
 * @property-read Collection<int, SectorRequest> $sectorRequests
 * @property-read int|null $sector_requests_count
 * @property-read Collection<int, Sector> $sectors
 * @property-read int|null $sectors_count
 * @property-read Collection<int, Settlement> $settlements
 * @property-read int|null $settlements_count
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 * @property-read Collection<int, Venue> $venues
 * @property-read int|null $venues_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereAccountHolder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereApprovedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereApprovedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereBankName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereBioAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereBioEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereCover($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereCrNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereIban($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereInternalNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereInternalRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereLat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereLegalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereLng($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereLogo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereNameAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereNameEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereNationalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereOwnerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereRatingAvg($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereRatingCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereSubmittedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereVatNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider whereWebsite($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provider withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Provider extends Model implements HasAvatar, HasName
{
    use Auditable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ProviderType::class,
            'status' => ProviderStatus::class,
            'internal_rating' => InternalRating::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rating_avg' => 'float',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }

    /** Tenant name in the provider panel's tenant switcher. */
    public function getFilamentName(): string
    {
        return $this->displayName();
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->logoUrl();
    }

    public function isApproved(): bool
    {
        return $this->status === ProviderStatus::Approved;
    }

    public function logoUrl(): ?string
    {
        return $this->logo ? Storage::disk('public')->url($this->logo) : null;
    }

    public function coverUrl(): ?string
    {
        return $this->cover ? Storage::disk('public')->url($this->cover) : null;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class)->withPivot('status')->withTimestamps();
    }

    public function approvedSectors(): BelongsToMany
    {
        return $this->sectors()->wherePivot('status', 'approved');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProviderDocument::class);
    }

    /** Documents the platform asked this store for while reviewing it. */
    public function documentRequests(): HasMany
    {
        return $this->hasMany(ProviderDocumentRequest::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProviderMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'provider_members')
            ->withPivot(['role', 'permissions', 'is_active'])
            ->withTimestamps();
    }

    public function sectorRequests(): HasMany
    {
        return $this->hasMany(SectorRequest::class);
    }

    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ProviderSubscription::class);
    }
}
