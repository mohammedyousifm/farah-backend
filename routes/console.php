<?php

use App\Models\Setting;
use App\Services\CampaignService;
use App\Services\OrderService;
use App\Services\ProviderService;
use App\Services\SettlementService;
use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Farah scheduled jobs
|--------------------------------------------------------------------------
| Production cron:  * * * * * php /path/to/artisan schedule:run
*/

Artisan::command('farah:expire-orders', function (OrderService $orders) {
    $this->info('Expired '.$orders->expireStale().' unpaid orders.');
})->purpose('Release seats of unpaid orders whose payment window passed');

Artisan::command('farah:complete-bookings', function (OrderService $orders) {
    $o = $orders->completeFinished();
    $asked = $orders->promptReviews();
    $this->info("Completed {$o} orders, asked {$asked} customers to rate their visit.");
})->purpose('Mark bookings whose time passed, and their orders, as completed');

Artisan::command('farah:document-expiry', function (ProviderService $providers) {
    [$warned, $expired] = $providers->checkDocumentExpiry();
    $this->info("Warned {$warned} owners, expired {$expired} documents.");
})->purpose('Warn about expiring provider documents and expire old ones');

Artisan::command('farah:generate-settlements {--force : Ignore the configured cycle day}', function (SettlementService $settlements) {
    $cycle = Setting::get('settlement_cycle');
    $due = match ($cycle) {
        'monthly' => now()->day === 1,
        'biweekly' => now()->isSunday() && now()->weekOfYear % 2 === 0,
        default => now()->isSunday(),
    };

    if (! $due && ! $this->option('force')) {
        $this->info("Not a {$cycle} settlement day.");

        return;
    }

    [$created, $skipped] = $settlements->generateAll(now()->subDay());
    $this->info("Created {$created} settlements ({$skipped} providers skipped).");
})->purpose('Group available provider balances into settlements for approval');

Artisan::command('farah:send-campaigns', function (CampaignService $campaigns) {
    $this->info('Sent '.$campaigns->sendDue().' scheduled campaigns.');
})->purpose('Send scheduled push campaigns that are due');

/*
 * Every job is idempotent (safe to re-run) and protected against overlapping runs:
 *  - expire-orders: row-locks each order, skips ones paid meanwhile;
 *  - complete-bookings: plain status transitions; the rating request is claimed per order (review_prompted_at);
 *  - document-expiry: expiry_notified_at guards the warning, expired docs are not re-selected;
 *  - generate-settlements: only unsettled entries are taken, provider row locked per run;
 *  - send-campaigns: each campaign is claimed under a row lock before sending.
 */
Schedule::command('farah:expire-orders')->everyMinute()->withoutOverlapping();
Schedule::command('farah:send-campaigns')->everyMinute()->withoutOverlapping();
Schedule::command('farah:complete-bookings')->everyFiveMinutes()->withoutOverlapping();
// Proof the scheduler is alive: "farah:doctor" and the admin dashboard warn when this goes stale.
Schedule::call(fn () => Cache::put(SchedulerHeartbeat::KEY, now()->timestamp, now()->addDay()))->everyMinute()->name('farah:heartbeat');

/*
 * Daily jobs are set in Saudi time (the app itself runs in UTC). All three run after 03:00 Riyadh,
 * when the UTC date and the Saudi date are the same day, so "is today Sunday / the 1st" in the
 * settlement job means the same thing in both. Keep any new daily job after 03:00 for the same reason.
 */
$saudi = config('farah.timezone');
Schedule::command('farah:prune')->dailyAt('03:30')->timezone($saudi)->withoutOverlapping();
Schedule::command('farah:generate-settlements')->dailyAt('05:00')->timezone($saudi)->withoutOverlapping(); // = 02:00 UTC, as before
Schedule::command('farah:document-expiry')->dailyAt('08:00')->timezone($saudi)->withoutOverlapping();
