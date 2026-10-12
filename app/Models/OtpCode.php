<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $identifier
 * @property string $channel
 * @property string $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode whereAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode whereCodeHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode whereConsumedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode whereIdentifier($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode whereIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode wherePurpose($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OtpCode whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class OtpCode extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
