<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $complaint_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $is_internal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Complaint $complaint
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ComplaintReply newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ComplaintReply newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ComplaintReply query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ComplaintReply whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ComplaintReply whereComplaintId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ComplaintReply whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ComplaintReply whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ComplaintReply whereIsInternal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ComplaintReply whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ComplaintReply whereUserId($value)
 *
 * @mixin \Eloquent
 */
class ComplaintReply extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
