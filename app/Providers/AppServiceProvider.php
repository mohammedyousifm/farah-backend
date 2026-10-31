<?php

namespace App\Providers;

use App\Http\Middleware\CompressResponse;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Category;
use App\Models\CommissionRule;
use App\Models\Complaint;
use App\Models\Dispute;
use App\Models\HomeSection;
use App\Models\Order;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\ProviderSubscription;
use App\Models\RecommendationRule;
use App\Models\Refund;
use App\Models\Review;
use App\Models\Sector;
use App\Models\SectorField;
use App\Models\SectorRequest;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Venue;
use App\Services\Integrations\Contracts\AccountingClient;
use App\Services\Integrations\Contracts\PaymentGateway;
use App\Services\Integrations\Contracts\PushSender;
use App\Services\Integrations\Contracts\SmsSender;
use App\Services\Integrations\Drivers\FakePaymentGateway;
use App\Services\Integrations\Drivers\FcmPushSender;
use App\Services\Integrations\Drivers\LogPushSender;
use App\Services\Integrations\Drivers\LogSmsSender;
use App\Services\Integrations\Drivers\NullAccountingClient;
use App\Services\OtpService;
use App\Support\PhoneScanner;
use Filament\Forms\Components\FileUpload;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Integration drivers per config('farah.integrations.*.driver').
     * Add real implementations here (e.g. 'moyasar' => MoyasarGateway::class).
     */
    private const DRIVERS = [
        PaymentGateway::class => ['payment', ['fake' => FakePaymentGateway::class]],
        SmsSender::class => ['sms', ['log' => LogSmsSender::class]],
        AccountingClient::class => ['accounting', ['null' => NullAccountingClient::class]],
        PushSender::class => ['push', ['log' => LogPushSender::class, 'fcm' => FcmPushSender::class]],
    ];

    public function register(): void
    {
        foreach (self::DRIVERS as $contract => [$service, $drivers]) {
            $this->app->singleton($contract, function ($app) use ($service, $drivers) {
                $driver = config("farah.integrations.{$service}.driver");

                return $app->make($drivers[$driver] ?? throw new \InvalidArgumentException("Unknown {$service} driver [{$driver}]"));
            });
        }
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'user' => User::class,
            'provider' => Provider::class,
            'provider_document' => ProviderDocument::class,
            'sector' => Sector::class,
            'category' => Category::class,
            'sector_field' => SectorField::class,
            'sector_request' => SectorRequest::class,
            'venue' => Venue::class,
            'activity' => Activity::class,
            'resource' => \App\Models\Resource::class,
            'booking' => Booking::class,
            'order' => Order::class,
            'refund' => Refund::class,
            'settlement' => Settlement::class,
            'dispute' => Dispute::class,
            'complaint' => Complaint::class,
            'review' => Review::class,
            'commission_rule' => CommissionRule::class,
            'home_section' => HomeSection::class,
            'page' => Page::class,
            'recommendation_rule' => RecommendationRule::class,
            'plan' => Plan::class,
            'provider_subscription' => ProviderSubscription::class,
        ]);

        // Super admins pass every permission check.
        Gate::before(fn (User $user) => $user->hasRole('super_admin') ? true : null);

        $this->harden();
        $this->recordSignIns();

        // An upload field's value is a path the browser sends back: without this a dashboard user could point their
        // record at another provider's file (a private document, someone else's logo) and read it or have it deleted.
        // Only the path the record already had, or a fresh upload, is accepted.
        FileUpload::configureUsing(fn (FileUpload $upload) => $upload->preventFilePathTampering());

        // Registered here so it runs after Livewire's own RequestHandled listener (script injection).
        Event::listen(RequestHandled::class, fn (RequestHandled $event) => CompressResponse::compress($event->request, $event->response));

        // Error pages with code and settings must never reach visitors, whatever .env says.
        if ($this->app->isProduction()) {
            config(['app.debug' => false]);
        }

        // Things a person does on purpose (book, pay, rate, complain): generous for a human, tight for a script.
        RateLimiter::for('actions', fn (Request $request) => Limit::perMinute(30)->by('actions:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        // Keyed on the normalised identifier, so "05..." / "+9665..." / "5..." share one budget.
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(5)->by('otp-ip:'.$request->ip()),
            Limit::perHour(10)->by('otp-id:'.OtpService::normalize((string) $request->input('identifier'))),
        ]);
    }

    /** Settings that must hold on a real server whatever the .env file says. */
    private function harden(): void
    {
        // New passwords on the dashboards: 8 characters or more with a capital and a small English letter,
        // a digit and a symbol (sign-up, profile, reset, team members).
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->symbols());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
            config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => config('session.same_site') ?: 'lax']);
        }

        // Queries slow enough for a person to notice are written to the log with their SQL.
        DB::listen(function ($query) {
            if ($query->time >= 500) {
                Log::warning('Slow query', ['ms' => round($query->time), 'sql' => $query->sql, 'connection' => $query->connectionName]);
            }
        });
    }

    /** Dashboard sign-ins, failures and lockouts go to the audit log; a phone scanner link dies with the session. */
    private function recordSignIns(): void
    {
        $panel = fn (?string $guard) => in_array($guard, ['web', 'provider'], true);

        Event::listen(Login::class, function (Login $event) use ($panel) {
            if ($panel($event->guard)) {
                AuditLog::record('auth.login', $event->user instanceof Model ? $event->user : null, [], ['guard' => $event->guard]);
            }
        });
        Event::listen(Failed::class, function (Failed $event) use ($panel) {
            if ($panel($event->guard)) {
                AuditLog::record('auth.failed', $event->user instanceof Model ? $event->user : null, [], ['guard' => $event->guard, 'email' => Str::limit((string) ($event->credentials['email'] ?? ''), 120)]);
            }
        });
        Event::listen(Lockout::class, fn (Lockout $event) => AuditLog::record('auth.lockout', null, [], ['email' => Str::limit((string) $event->request->input('email', $event->request->input('data.email', '')), 120)]));
        Event::listen(Logout::class, fn () => PhoneScanner::disconnect(session('farah.phone_scan')));
    }
}
