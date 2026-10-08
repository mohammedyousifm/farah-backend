<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Human-readable unique references: ORD-260928-7KQ2M, RF-..., STL-..., CMP-... */
class DocNumber
{
    public static function make(string $prefix, string $table, string $column = 'number'): string
    {
        do {
            $number = $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (DB::table($table)->where($column, $number)->exists());

        return $number;
    }

    /** Booking confirmation code: 10 chars, no ambiguous characters. */
    public static function bookingCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (DB::table('bookings')->where('code', $code)->exists());

        return $code;
    }
}
