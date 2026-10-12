<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $device_id
 * @property string $name
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<array-key, mixed>|null $properties
 * @property Carbon $created_at
 * @property-read Model|\Eloquent|null $subject
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BehaviorEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BehaviorEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BehaviorEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BehaviorEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BehaviorEvent whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BehaviorEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BehaviorEvent whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BehaviorEvent whereProperties($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BehaviorEvent whereSubjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BehaviorEvent whereSubjectType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BehaviorEvent whereUserId($value)
 *
 * @mixin \Eloquent
 */
class BehaviorEvent extends Model
{
    public const UPDATED_AT = null;

    public const NAMES = ['view_venue', 'view_category', 'search', 'favorite', 'start_booking', 'purchase', 'share'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
