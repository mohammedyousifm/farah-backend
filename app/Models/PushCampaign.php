<?php

namespace App\Models;

use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title_ar
 * @property string|null $title_en
 * @property string $body_ar
 * @property string|null $body_en
 * @property string $audience
 * @property string $link_type
 * @property string|null $link_value
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $sent_at
 * @property int $recipients_count
 * @property string $status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereAudience($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereBodyAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereBodyEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereLinkType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereLinkValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereRecipientsCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereScheduledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereSentAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereTitleAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereTitleEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushCampaign whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class PushCampaign extends Model
{
    use Localized;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
