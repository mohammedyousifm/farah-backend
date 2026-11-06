<?php

namespace Tests\Feature\Provider;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentStatus;
use App\Enums\ProviderStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\BusinessException;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\User;
use App\Rules\ArabicText;
use App\Services\Integrations\Drivers\FakePaymentGateway;
use App\Services\PaymentService;
use App\Services\ProviderService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Fixes from the QA report of 6 Nov 2026 (BUG-ADM-001 … 005, FARAH-DATA-001, FARAH-WF-003).
 */
class QaFixesTest extends ProviderPanelTestCase
{
    private function document(Provider $provider, DocumentType $type = DocumentType::CommercialRegister, DocumentStatus $status = DocumentStatus::Approved, ?int $sectorId = null): ProviderDocument
    {
        Storage::disk(ProviderDocument::DISK)->put("providers/{$provider->id}/documents/cr.pdf", '%PDF-1.4 test');

        return $provider->documents()->create([
            'type' => $type, 'title' => $type->value, 'status' => $status, 'sector_id' => $sectorId,
            'file_path' => "providers/{$provider->id}/documents/cr.pdf",
        ]);
    }

    private function pendingProvider(): Provider
    {
        $provider = $this->provider(name: 'Pending Store');
        $provider->update(['status' => ProviderStatus::Pending, 'approved_at' => null]);
        $provider->sectors()->updateExistingPivot($this->sector->id, ['status' => 'pending']);

        return $provider->fresh();
    }

    // BUG-ADM-001 -------------------------------------------------------------------------------------------

    public function test_document_preview_checks_the_signed_in_person_on_every_open(): void
    {
        Storage::fake(ProviderDocument::DISK);
        $doc = $this->document($this->provider);
        $url = $doc->panelUrl();
        $other = $this->provider(name: 'Other Store');
        $support = User::create(['name' => 'Support', 'email' => 'support@farah.test', 'password' => 'password', 'type' => 'admin', 'status' => 'active']);
        $support->assignRole('support');

        $this->get($url)->assertForbidden(); // nobody signed in

        $this->actingAs($this->admin, 'web')->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        auth('web')->logout();

        $this->actingAs($this->owner, 'provider')->get($url)->assertOk(); // its own store
        auth('provider')->logout();

        $this->actingAs($other->owner, 'provider')->get($url)->assertForbidden(); // another store

        if (! $support->can('documents.view') && ! $support->can('documents.manage')) {
            auth('provider')->logout();
            $this->actingAs($support, 'web')->get($url)->assertForbidden(); // admin without the permission
        }
    }

    public function test_api_document_links_are_signed_and_stay_off_the_public_storage_path(): void
    {
        // The host drops the query string of URLs ending in a file extension: the path rides in the query.
        $path = 'qa-test/'.Str::random(8).'.jpg';
        Storage::disk('local')->put($path, 'image-bytes');

        try {
            $url = Storage::disk('local')->temporaryUrl($path, now()->addMinutes(5));
            $this->assertStringContainsString('/private-file?', $url);
            $this->assertDoesNotMatchRegularExpression('#\.jpg(\?|$)#', parse_url($url, PHP_URL_PATH));

            $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
            $this->get(route('files.private', ['path' => $path]))->assertForbidden(); // unsigned
        } finally {
            Storage::disk('local')->deleteDirectory('qa-test');
        }
    }

    public function test_signed_links_survive_the_hosting_proxy(): void
    {
        // The proxy ends HTTPS and talks plain http to PHP; X-Forwarded-Proto tells Laravel the real scheme.
        $request = Request::create('http://farah.test/up', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https']);
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->app['request']->isSecure(), 'forwarded https must be trusted');
    }

    // FARAH-DATA-001 ----------------------------------------------------------------------------------------

    public function test_an_arabic_name_needs_arabic_letters(): void
    {
        $check = fn (string $value) => Validator::make(['name_ar' => $value], ['name_ar' => [new ArabicText]])->passes();

        $this->assertFalse($check('Xena Cochran'));
        $this->assertTrue($check('صالة العليا'));
        $this->assertTrue($check('قيمرز PS5'));
    }

    // FARAH-WF-003 ------------------------------------------------------------------------------------------

    public function test_a_store_is_not_approved_without_an_approved_commercial_register(): void
    {
        Storage::fake(ProviderDocument::DISK);
        $provider = $this->pendingProvider();
        $service = app(ProviderService::class);

        try {
            $service->approve($provider, $this->admin);
            $this->fail('approved with no documents');
        } catch (BusinessException $e) {
            $this->assertSame('missing_commercial_register', $e->errorCode);
        }

        $this->sector->update(['requires_license' => true]);
        $this->document($provider);
        try {
            $service->approve($provider, $this->admin);
            $this->fail('approved without the licence');
        } catch (BusinessException $e) {
            $this->assertSame('missing_license', $e->errorCode);
        }

        $this->document($provider, DocumentType::License, sectorId: $this->sector->id);
        $service->approve($provider, $this->admin);
        $this->assertSame(ProviderStatus::Approved, $provider->fresh()->status);
    }

    // BUG-ADM-005 -------------------------------------------------------------------------------------------

    private function paidPlan(int $days = 30): Plan
    {
        return Plan::create(['name_ar' => 'احترافية', 'name_en' => 'Pro', 'price' => 499, 'duration_days' => $days, 'is_free' => false]);
    }

    private function webhook(Payment $payment, string $type = 'payment.paid', ?string $eventId = null): bool
    {
        $body = json_encode(['id' => $eventId ?? 'evt_'.Str::random(8), 'type' => $type, 'data' => [
            'reference' => $payment->reference, 'id' => 'pay_1', 'method' => 'mada', 'amount' => $payment->amount, 'currency' => $payment->currency,
        ]]);

        return app(PaymentService::class)->handleWebhook($body, FakePaymentGateway::sign($body));
    }

    public function test_a_paid_plan_starts_only_when_the_gateway_confirms_the_payment(): void
    {
        $service = app(SubscriptionService::class);
        $subscription = $service->request($this->provider, $this->paidPlan(), $this->owner);

        $payment = $service->checkout($subscription, $this->owner, 'https://farah.test/provider/my-plan');
        $this->assertSame(PaymentStatus::Initiated, $payment->status);
        $this->assertEquals(499.0, $payment->amount, 'the price comes from the server');
        $this->assertNotEmpty($payment->checkout_url);
        $this->assertSame(SubscriptionStatus::Pending, $subscription->fresh()->status, 'opening the checkout grants nothing');

        $this->webhook($payment, 'payment.failed');
        $this->assertSame(SubscriptionStatus::Pending, $subscription->fresh()->status, 'a failed payment grants nothing');

        $retry = $service->checkout($subscription->fresh(), $this->owner);
        $this->assertTrue($this->webhook($retry, eventId: 'evt_ok'));
        $this->assertFalse($this->webhook($retry, eventId: 'evt_ok'), 'the same event twice is ignored');

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame($retry->reference, $subscription->payment_reference);
        $this->assertSame(1, $this->provider->subscriptions()->where('status', SubscriptionStatus::Active)->count());
    }

    public function test_a_pending_provider_cannot_pay_yet(): void
    {
        $provider = $this->pendingProvider();
        $service = app(SubscriptionService::class);
        $subscription = $service->request($provider, $this->paidPlan(), $provider->owner);

        $this->expectException(BusinessException::class);
        $service->checkout($subscription, $provider->owner);
    }

    public function test_renewing_the_running_plan_starts_when_it_ends(): void
    {
        $service = app(SubscriptionService::class);
        $plan = $this->paidPlan();
        $first = $service->assign($this->provider, $plan, $this->admin);
        $this->travel(28)->days();

        $renewal = $service->request($this->provider, $plan, $this->owner);
        $this->webhook($service->checkout($renewal, $this->owner));

        $this->assertSame(SubscriptionStatus::Active, $first->fresh()->status, 'the running period is kept');
        $this->assertEquals($first->fresh()->ends_at->timestamp, $renewal->fresh()->starts_at->timestamp);
    }

    public function test_the_owner_is_told_once_before_the_plan_ends(): void
    {
        $service = app(SubscriptionService::class);
        $service->assign($this->provider, $this->paidPlan(), $this->admin);
        $this->travel(28)->days();

        $this->assertSame(1, $service->remindExpiring());
        $this->assertSame(0, $service->remindExpiring());
        $this->assertSame(1, $this->owner->notifications()->where('data->key', 'subscription_expiring')->count());
    }
}
