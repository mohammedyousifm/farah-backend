<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an immutable audit_logs row for every create / update / delete.
 * Hidden attributes (passwords, tokens) are never recorded.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => AuditLog::record('created', $model, [], $model->auditValues($model->getAttributes())));

        static::updated(function (Model $model) {
            $changes = $model->auditValues($model->getChanges());
            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $old = array_intersect_key($model->auditValues($model->getOriginal()), $changes);
            AuditLog::record('updated', $model, $old, $changes);
        });

        static::deleted(fn (Model $model) => AuditLog::record('deleted', $model, $model->auditValues($model->getAttributes()), []));
    }

    protected function auditValues(array $values): array
    {
        $values = array_diff_key($values, array_flip(array_merge($this->getHidden(), ['remember_token', 'password'])));

        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $values);
    }
}
