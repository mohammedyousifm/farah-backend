<?php

namespace App\Models;

use App\Enums\ComplaintStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property int|null $user_id
 * @property int|null $provider_id
 * @property int|null $order_id
 * @property int|null $venue_id
 * @property string $type
 * @property string $subject
 * @property string $body
 * @property string $priority
 * @property ComplaintStatus $status
 * @property string|null $resolution
 * @property int|null $assigned_to
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $assignee
 * @property-read Order|null $order
 * @property-read Provider|null $provider
 * @property-read Collection<int, ComplaintReply> $replies
 * @property-read int|null $replies_count
 * @property-read User|null $user
 * @property-read Venue|null $venue
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereAssignedTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint wherePriority($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereResolution($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereResolvedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Complaint whereVenueId($value)
 *
 * @mixin \Eloquent
 */
class Complaint extends Model
{
    public const TYPES = ['complaint', 'feedback', 'suggestion'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => ComplaintStatus::class, 'resolved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class)->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ComplaintReply::class)->latest();
    }
}
