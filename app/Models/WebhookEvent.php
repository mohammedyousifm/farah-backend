<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $gateway
 * @property string $event_id
 * @property string|null $type
 * @property array<array-key, mixed> $payload
 * @property bool $signature_valid
 * @property Carbon|null $processed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereEventId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereGateway($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereProcessedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereSignatureValid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class WebhookEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'signature_valid' => 'boolean', 'processed_at' => 'datetime'];
    }
}
