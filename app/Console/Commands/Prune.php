<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Daily housekeeping: removes what no longer has a use, so personal data is not kept longer than
 * needed and the busiest tables stay small. Financial records and the audit log are never touched.
 */
class Prune extends Command
{
    protected $signature = 'farah:prune';

    protected $description = 'Delete expired sign-in codes and tokens, and old technical logs';

    /** table => [date column, days kept, extra condition]. */
    private const RULES = [
        'otp_codes' => ['expires_at', 1, null],                 // a used or expired code is worthless a day later
        'behavior_events' => ['created_at', 180, null],          // browsing analytics
        'integration_logs' => ['created_at', 90, null],          // calls to SMS / payment / push providers
        'webhook_events' => ['created_at', 180, null],
        'notifications' => ['created_at', 120, 'read_at IS NOT NULL'], // only ones the person has already read
    ];

    public function handle(): int
    {
        foreach (self::RULES as $table => [$column, $days, $where]) {
            $deleted = DB::table($table)->where($column, '<', now()->subDays($days))->when($where, fn ($q) => $q->whereRaw($where))->delete();
            $this->line(sprintf('%-18s %d removed (older than %d days)', $table, $deleted, $days));
        }

        $tokens = PersonalAccessToken::whereNotNull('expires_at')->where('expires_at', '<', now())->delete();
        $this->line(sprintf('%-18s %d removed (expired)', 'access tokens', $tokens));

        return self::SUCCESS;
    }
}
