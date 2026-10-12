<?php

namespace App\Support;

/**
 * Builds LIKE patterns from text people type. "%" and "_" mean "anything" to the database, so a search
 * for "50%" or "a_b" must have them escaped, or it matches rows it should not (and "%%%" scans everything).
 */
class Like
{
    /** A pattern matching rows that contain the text anywhere. */
    public static function contains(?string $term): string
    {
        return '%'.self::escape((string) $term).'%';
    }

    public static function escape(string $term): string
    {
        return addcslashes($term, '\\%_');
    }
}
