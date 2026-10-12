<?php

namespace App\Models;

use App\Enums\RequestStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $provider_id
 * @property string $name_ar
 * @property string|null $name_en
 * @property string|null $description
 * @property RequestStatus $status
 * @property string|null $admin_notes
 * @property int|null $sector_id
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Provider|null $provider
 * @property-read User|null $reviewer
 * @property-read Sector|null $sector
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest whereAdminNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest whereNameAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest whereNameEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest whereProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest whereReviewedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest whereReviewedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest whereSectorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SectorRequest whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class SectorRequest extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => RequestStatus::class, 'reviewed_at' => 'datetime'];
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
}
