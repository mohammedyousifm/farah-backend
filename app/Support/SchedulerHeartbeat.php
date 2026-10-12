<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * "Is the background scheduler alive?" The scheduler stamps the time every minute
 * (routes/console.php); the admin dashboard and farah:doctor ask here.
 */
class SchedulerHeartbeat
{
    public const KEY = 'farah.scheduler.heartbeat';

    /** True when the scheduler ran within the last ten minutes. */
    public static function isFresh(): bool
    {
        $stamp = Cache::get(self::KEY);

        return is_numeric($stamp) && now()->timestamp - (int) $stamp < 600;
    }
}
