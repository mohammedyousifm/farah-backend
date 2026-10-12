<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $service
 * @property string $action
 * @property array<array-key, mixed>|null $request
 * @property array<array-key, mixed>|null $response
 * @property bool $success
 * @property int|null $duration_ms
 * @property Carbon $created_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereDurationMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereResponse($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereService($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereSuccess($value)
 *
 * @mixin \Eloquent
 */
class IntegrationLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['request' => 'array', 'response' => 'array', 'success' => 'boolean'];
    }
}
