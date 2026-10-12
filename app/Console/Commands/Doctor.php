<?php

namespace App\Console\Commands;

use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * "Is this server ready for real customers?" One line per check, with what to change when it is not.
 * Run after every deployment: php artisan farah:doctor
 */
class Doctor extends Command
{
    protected $signature = 'farah:doctor {--strict : Treat warnings as failures (for CI / deployment scripts)}';

    protected $description = 'Check that the server is configured safely and completely';

    public function handle(): int
    {
        $production = app()->isProduction();
        $failed = $warned = 0;

        foreach ($this->checks($production) as [$label, $state, $advice]) {
            // In production a warning about safety is a failure; on a developer machine it is only a note.
            $state = $state === 'fail' && ! $production ? 'warn' : $state;
            $failed += $state === 'fail';
            $warned += $state === 'warn';

            $mark = ['ok' => '<info>  OK  </info>', 'warn' => '<comment> WARN </comment>', 'fail' => '<error> FAIL </error>'][$state];
            $this->line($mark.' '.$label.($state === 'ok' ? '' : "  →  {$advice}"));
        }

        $this->newLine();
        $this->line($failed + $warned === 0 ? '<info>Everything looks right.</info>' : "{$failed} to fix, {$warned} to review.");

        return $failed > 0 || ($this->option('strict') && $warned > 0) ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, array{0: string, 1: 'ok'|'warn'|'fail', 2: string}> */
    private function checks(bool $production): array
    {
        $secret = (string) config('farah.integrations.payment.webhook_secret');
        $heartbeat = SchedulerHeartbeat::isFresh();
        $credentials = (string) config('farah.integrations.push.credentials');

        return [
            ['Database reachable and migrated', $this->try(fn () => DB::connection()->getPdo() && Schema::hasTable('migrations') && ! $this->pendingMigrations()) ? 'ok' : 'fail',
                'run: php artisan migrate --force'],
            ['Debug mode off', config('app.debug') ? 'fail' : 'ok', 'set APP_DEBUG=false'],
            ['Site served over HTTPS', str_starts_with((string) config('app.url'), 'https://') ? 'ok' : 'fail', 'set APP_URL to the https:// address'],
            ['Payment webhook secret set', $secret !== '' && $secret !== 'change-me' ? 'ok' : 'fail', 'set PAYMENT_WEBHOOK_SECRET to a long random value'],
            ['Real payment gateway', config('farah.integrations.payment.driver') === 'fake' ? 'fail' : 'ok', 'PAYMENT_DRIVER is "fake": nobody is charged'],
            ['Fixed sign-in code off', filled(config('farah.otp.dev_code')) ? 'fail' : 'ok', 'remove OTP_DEV_CODE: it lets anyone sign in as anyone'],
            ['Real SMS provider', config('farah.integrations.sms.driver') === 'log' ? 'fail' : 'ok', 'SMS_DRIVER is "log": sign-in codes are not sent'],
            ['Push notifications', match (true) {
                config('farah.integrations.push.driver') !== 'fcm' => 'warn',
                $credentials === '' || ! is_file($credentials) => 'fail',
                default => 'ok',
            }, 'set PUSH_DRIVER=fcm and FIREBASE_CREDENTIALS to the service-account file'],
            ['E-mail delivery', in_array(config('mail.default'), ['log', 'array'], true) ? 'warn' : 'ok', 'MAIL_MAILER is "'.config('mail.default').'": e-mails are not sent'],
            ['Scheduler running', $heartbeat ? 'ok' : 'fail',
                'add the cron line: * * * * * php artisan schedule:run (orders will not expire and bookings will not complete without it)'],
            ['Private storage writable', $this->try(fn () => Storage::disk('local')->put('.doctor', 'ok') && Storage::disk('local')->delete('.doctor')) ? 'ok' : 'fail',
                'make storage/app writable by the web server'],
            ['Public storage linked', is_dir(public_path('storage')) ? 'ok' : 'fail', 'run: php artisan storage:link'],
            ['Cache and sessions off the database', $production && (config('cache.default') === 'database' || config('session.driver') === 'database') ? 'warn' : 'ok',
                'use Redis for CACHE_STORE and SESSION_DRIVER under real traffic'],
            ['Configuration cached', $production && ! app()->configurationIsCached() ? 'warn' : 'ok', 'run: php artisan optimize'],
            ['API limited to your own websites', $production && in_array('*', (array) config('cors.allowed_origins'), true) ? 'fail' : 'ok', 'set CORS_ALLOWED_ORIGINS'],
        ];
    }

    private function pendingMigrations(): bool
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        $files = array_map(fn (string $path) => basename($path, '.php'), glob(database_path('migrations/*.php')) ?: []);

        return array_diff($files, $ran) !== [];
    }

    private function try(callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (Throwable) {
            return false;
        }
    }
}
