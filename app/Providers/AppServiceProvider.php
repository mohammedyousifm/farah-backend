<?php

namespace App\Providers;

use App\Models\Activity;
use App\Models\Booking;
use App\Models\Category;
use App\Models\CommissionRule;
use App\Models\Complaint;
use App\Models\Dispute;
use App\Models\HomeSection;
use App\Models\Order;
use App\Models\Page;
use App\Models\Provider;
use App\Models\ProviderDocument;
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
use App\Services\Integrations\Contracts\WathqClient;
use App\Services\Integrations\Drivers\FakePaymentGateway;
use App\Services\Integrations\Drivers\FakeWathqClient;
use App\Services\Integrations\Drivers\LogPushSender;
use App\Services\Integrations\Drivers\LogSmsSender;
use App\Services\Integrations\Drivers\NullAccountingClient;
use App\Services\OtpService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Integration drivers per config('farah.integrations.*.driver').
     * Add real implementations here (e.g. 'moyasar' => MoyasarGateway::class).
     */
    private const DRIVERS = [
        PaymentGateway::class => ['payment', ['fake' => FakePaymentGateway::class]],
        SmsSender::class => ['sms', ['log' => LogSmsSender::class]],
        WathqClient::class => ['wathq', ['fake' => FakeWathqClient::class]],
        AccountingClient::class => ['accounting', ['null' => NullAccountingClient::class]],
        PushSender::class => ['push', ['log' => LogPushSender::class]],
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
        ]);

        // Super admins pass every permission check.
        Gate::before(fn (User $user) => $user->hasRole('super_admin') ? true : null);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        // Keyed on the normalised identifier, so "05..." / "+9665..." / "5..." share one budget.
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(5)->by('otp-ip:'.$request->ip()),
            Limit::perHour(10)->by('otp-id:'.OtpService::normalize((string) $request->input('identifier'))),
        ]);
    }
}
