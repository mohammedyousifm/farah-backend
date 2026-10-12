<?php

namespace App\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Guards one-off decisions (approve, reject, suspend, ...) against a second click or a second admin
 * doing the same thing at the same moment: the decision and its notification happen once.
 */
class Once
{
    /**
     * True for the first caller only. False when the record is already in the target state, or when
     * the same decision on the same record is being taken right now by another request.
     */
    public static function first(Model $model, string $column, mixed $target): bool
    {
        $value = $target instanceof BackedEnum ? $target->value : $target;
        $lock = Cache::lock("once:{$model->getTable()}:{$model->getKey()}:{$column}:{$value}", 5);

        if (! $lock->get()) {
            return false;
        }

        // Read fresh from the database: the copy in memory may be older than the first click.
        $current = $model->newQueryWithoutScopes()->whereKey($model->getKey())->value($column);
        $current = $current instanceof BackedEnum ? $current->value : $current;

        if ((string) $current === (string) $value) {
            $lock->release();

            return false;
        }

        // Held while the caller makes the change, so a twin arriving meanwhile is turned away above;
        // let go when this request ends (or after 5 seconds at the latest).
        app()->terminating(fn () => $lock->release());

        return true;
    }
}
