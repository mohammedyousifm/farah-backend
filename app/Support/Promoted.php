<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/** Orders stores whose plan pays for a placement before everything else in a venue list. */
class Promoted
{
    /**
     * @param  array<int, int>  $ids  ids from the database, cast to int before they are inlined
     */
    public static function first(Builder $query, array $ids, string $column = 'venues.id'): Builder
    {
        if ($ids !== []) {
            $query->orderByRaw("{$column} in (".implode(',', array_map('intval', $ids)).') desc');
        }

        return $query;
    }
}
