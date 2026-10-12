<?php

namespace Tests\Feature\Api;

use App\Enums\ComplaintStatus;
use App\Models\BookingSlot;
use App\Models\Complaint;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Integrations\Drivers\FakePaymentGateway;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/** IDOR, privilege escalation, upload validation, OTP brute force and token hygiene. */
class SecurityTest extends ApiTestCase
{
    public function test_customer_cannot_touch_another_customers_resources(): void
    {
        $venue = $this->venue($this->provider());
        $owner = $this->customer('+966501010101');
        $order = app(OrderService::class)->createOnline($owner, [$this->line($venue, 1)]);
        $payment = app(PaymentService::class)->checkout($owner, collect([$order]));
        $orderId = $order->id;
        $bookingId = $order->bookings->first()->id;
        $complaint = Complaint::create(['number' => 'CMP-SEC-1', 'user_id' => $owner->id, 'type' => 'complaint',
            'subject' => 's', 'body' => 'b', 'status' => ComplaintStatus::Open]);
        SystemNotification::send($owner, 'payment_failed');
        $notificationId = $owner->notifications()->value('id');

        Sanctum::actingAs($this->customer('+966502020202'));

        $this->getJson("/api/v1/orders/{$orderId}")->assertNotFound();
        $this->postJson("/api/v1/orders/{$orderId}/pay")->assertNotFound();
        $this->getJson("/api/v1/orders/{$orderId}/refund-quote")->assertNotFound();
        $this->getJson("/api/v1/payments/{$payment->reference}")->assertNotFound();
        $this->postJson("/api/v1/orders/{$orderId}/refund", ['items' => [['booking_id' => $bookingId]]])->assertNotFound();
        $this->getJson("/api/v1/bookings/{$bookingId}")->assertNotFound();
        $this->getJson('/api/v1/bookings?scope=all')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/complaints/{$complaint->id}")->assertNotFound();
        $this->postJson("/api/v1/complaints/{$complaint->id}/replies", ['body' => 'x'])->assertNotFound();
        $this->postJson('/api/v1/complaints', ['type' => 'complaint', 'subject' => 's', 'body' => 'b', 'order_id' => $orderId])->assertNotFound();
        $this->postJson('/api/v1/reviews', ['order_id' => $orderId, 'rating' => 1])->assertForbidden();
        $this->postJson("/api/v1/notifications/{$notificationId}/read")->assertNotFound();

        $this->assertSame('pending_payment', $order->fresh()->status->value);
        $this->assertSame(1, BookingSlot::count());
    }

    public function test_signed_in_endpoints_reject_guests_and_suspended_users(): void
    {
        $venue = $this->venue($this->provider());

        $this->postJson('/api/v1/bookings', $this->line($venue))->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/bookings')->assertUnauthorized();
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->postJson("/api/v1/favorites/{$venue->id}")->assertUnauthorized();
        $this->getJson('/api/v1/provider/venues')->assertUnauthorized();
        $this->postJson('/api/v1/provider/bookings/check-in', ['code' => 'ABCDEFGHJK'])->assertUnauthorized();

        // The removed ticketing endpoints are gone for good.
        foreach (['/api/v1/events', '/api/v1/cart', '/api/v1/tickets'] as $removed) {
            $this->getJson($removed)->assertNotFound();
        }
        $this->postJson('/api/v1/checkout')->assertNotFound();

        Sanctum::actingAs($this->customer('+966509090909', ['status' => 'suspended']));
        $this->postJson('/api/v1/bookings', $this->line($venue))->assertForbidden();
        $this->assertSame(0, BookingSlot::count());
    }

    public function test_customer_cannot_use_provider_endpoints(): void
    {
        $venue = $this->venue($this->provider());
        $table = $this->activity($venue)->resources->first();
        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/provider/venues')->assertForbidden()->assertJsonPath('code', 'not_provider_member');
        $this->getJson("/api/v1/provider/venues/{$venue->id}/schedule")->assertForbidden();
        $this->putJson("/api/v1/provider/venues/{$venue->id}", ['name_ar' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/provider/venues/{$venue->id}/blocks", ['resource_id' => $table->id, 'starts_at' => $this->slot()->toIso8601String(), 'slots' => 1])->assertForbidden();
        $this->postJson('/api/v1/provider/orders/offline', $this->line($venue) + ['payment_method' => 'cash', 'customer' => ['name' => 'x']])->assertForbidden();

        $this->assertSame('مركز تجريبي', $venue->fresh()->name_ar);
        $this->assertSame(0, BookingSlot::count());
    }

    public function test_provider_member_cannot_reach_another_providers_resources(): void
    {
        $mine = $this->provider(null, 'Mine');
        $theirs = $this->provider(null, 'Theirs');
        $venue = $this->venue($theirs);
        $activity = $this->activity($venue);
        $table = $activity->resources->first();
        $media = $venue->media()->create(['type' => 'image', 'url' => 'https://example.com/a.jpg', 'sort_order' => 0]);
        $member = $theirs->members()->create(['user_id' => $this->customer('+966503030303')->id, 'role' => 'staff', 'is_active' => true]);
        $blocked = BookingSlot::create(['resource_id' => $table->id, 'starts_at' => $this->slot(), 'block_reason' => 'maintenance']);

        // My own venue: their activities / resources must not be reachable through it either.
        $myVenue = $this->venue($mine);
        $base = "/api/v1/provider/venues/{$venue->id}";
        $crossed = "/api/v1/provider/venues/{$myVenue->id}";

        Sanctum::actingAs($mine->owner);

        $this->getJson("{$base}/activities")->assertNotFound();
        $this->putJson("{$base}/activities/{$activity->id}", ['price_per_hour' => 1])->assertNotFound();
        $this->putJson("{$crossed}/activities/{$activity->id}", ['price_per_hour' => 1])->assertNotFound();
        $this->deleteJson("{$base}/activities/{$activity->id}")->assertNotFound();
        $this->postJson("{$base}/activities/{$activity->id}/resources", ['name_ar' => 'x'])->assertNotFound();
        $this->putJson("{$base}/activities/{$activity->id}/resources/{$table->id}", ['is_active' => false])->assertNotFound();
        $this->putJson("{$crossed}/activities/{$this->activity($myVenue)->id}/resources/{$table->id}", ['is_active' => false])->assertNotFound();
        $this->deleteJson("{$base}/activities/{$activity->id}/resources/{$table->id}")->assertNotFound();
        $this->deleteJson("{$base}/media/{$media->id}")->assertNotFound();
        $this->deleteJson("{$crossed}/media/{$media->id}")->assertNotFound();
        $this->putJson("{$base}/hours", ['hours' => [['weekday' => 0, 'is_closed' => true]]])->assertNotFound();
        $this->getJson("{$base}/schedule")->assertNotFound();
        $this->postJson("{$base}/blocks", ['resource_id' => $table->id, 'starts_at' => $this->slot(4)->toIso8601String(), 'slots' => 1])->assertNotFound();
        $this->postJson("{$crossed}/blocks", ['resource_id' => $table->id, 'starts_at' => $this->slot(4)->toIso8601String(), 'slots' => 1])->assertNotFound();
        $this->deleteJson("{$base}/blocks/{$blocked->id}")->assertNotFound();
        $this->deleteJson("{$crossed}/blocks/{$blocked->id}")->assertNotFound();
        $this->postJson("{$base}/submit")->assertNotFound();
        $this->deleteJson($base)->assertNotFound();
        $this->putJson("/api/v1/provider/members/{$member->id}", ['role' => 'manager'])->assertNotFound();
        $this->deleteJson("/api/v1/provider/members/{$member->id}")->assertNotFound();

        $this->assertEquals(100.0, $activity->fresh()->price_per_hour);
        $this->assertTrue($table->fresh()->is_active);
        $this->assertSame(1, BookingSlot::count());
        $this->assertSame(7, $venue->hours()->where('is_closed', false)->count());
        $this->assertSame('staff', $member->fresh()->role);
    }

    public function test_staff_cannot_escalate_privileges(): void
    {
        $provider = $this->provider();
        $staffUser = $this->customer('+966504040404');
        $provider->members()->create(['user_id' => $staffUser->id, 'role' => 'staff', 'permissions' => ['team.manage', 'bookings.checkin'], 'is_active' => true]);
        $manager = $provider->members()->create(['user_id' => $this->customer('+966505050505')->id, 'role' => 'manager', 'is_active' => true]);

        Sanctum::actingAs($staffUser);

        $this->postJson('/api/v1/provider/members', ['phone' => '0506060606', 'role' => 'manager'])
            ->assertForbidden()->assertJsonPath('code', 'cannot_grant');
        $this->postJson('/api/v1/provider/members', ['phone' => '0506060606', 'role' => 'staff', 'permissions' => ['finance.view']])
            ->assertForbidden()->assertJsonPath('code', 'cannot_grant');
        $this->deleteJson("/api/v1/provider/members/{$manager->id}")->assertForbidden();

        // Within their own permissions it works, and the invitee is notified.
        $this->postJson('/api/v1/provider/members', ['phone' => '0506060606', 'role' => 'staff', 'permissions' => ['bookings.checkin']])
            ->assertCreated();
        $invited = User::where('phone', '+966506060606')->firstOrFail();
        $this->assertSame(1, $invited->notifications()->where('data->key', 'team_invited')->count());
        $this->assertStringContainsString($provider->name_ar, $invited->notifications()->first()->data['body_ar']);
    }

    public function test_suspended_provider_is_read_only(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $provider->update(['status' => 'suspended']);
        Sanctum::actingAs($provider->owner);

        $this->getJson('/api/v1/provider/venues')->assertOk();
        $this->getJson("/api/v1/provider/venues/{$venue->id}/schedule")->assertOk();
        $this->postJson('/api/v1/provider/orders/offline', $this->line($venue) + ['payment_method' => 'cash', 'customer' => ['name' => 'x']])
            ->assertForbidden()->assertJsonPath('code', 'provider_suspended');
        $this->putJson("/api/v1/provider/venues/{$venue->id}", ['name_ar' => 'x'])->assertForbidden()->assertJsonPath('code', 'provider_suspended');
        $this->putJson('/api/v1/provider/profile', ['name_ar' => 'x'])->assertForbidden();

        // Customers cannot book there either.
        Sanctum::actingAs($this->customer());
        $this->postJson('/api/v1/bookings', $this->line($venue))->assertStatus(422)->assertJsonPath('code', 'not_bookable');
    }

    public function test_sensitive_provider_fields_need_profile_or_finance_access(): void
    {
        $provider = $this->provider();
        $provider->update(['iban' => 'SA0380000000608010167519']);
        $staff = $this->customer('+966507070707');
        $provider->members()->create(['user_id' => $staff->id, 'role' => 'staff', 'permissions' => ['bookings.checkin'], 'is_active' => true]);

        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/provider/profile')->assertOk()->assertJsonMissingPath('data.iban');

        Sanctum::actingAs($provider->owner);
        $this->getJson('/api/v1/provider/profile')->assertOk()->assertJsonPath('data.iban', 'SA0380000000608010167519');

        // Public profile never exposes them.
        $this->getJson("/api/v1/providers/{$provider->id}")->assertOk()->assertJsonMissingPath('data.iban');
    }

    public function test_uploads_reject_svg_html_and_script_urls(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $provider = $this->provider();
        $venue = $this->venue($provider);
        Sanctum::actingAs($provider->owner);

        // Real temp files: MIME types are sniffed from the content, as in production.
        $svg = $this->realUpload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $html = $this->realUpload('doc.html', '<html><script>alert(1)</script></html>');
        $php = $this->realUpload('shell.php.jpg', '<?php system($_GET["c"]); ?>');

        $this->post('/api/v1/provider/profile', ['logo' => $svg], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('logo');
        $this->post('/api/v1/provider/documents', ['type' => 'commercial_register', 'file' => $html], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post("/api/v1/provider/venues/{$venue->id}/media", ['file' => $php], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson("/api/v1/provider/venues/{$venue->id}/media", ['url' => 'javascript:alert(1)'])
            ->assertStatus(422)->assertJsonValidationErrors('url');
        $this->post("/api/v1/provider/venues/{$venue->id}", ['cover' => $svg], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('cover');

        Sanctum::actingAs($this->customer('+966508080808'));
        $this->post('/api/v1/me', ['avatar' => $svg], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('avatar');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_otp_is_locked_after_max_attempts_and_single_use(): void
    {
        // Isolate the per-code attempt counter from the request throttle (tested separately).
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '0501212121'])->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '0501212121', 'code' => '0000'])
                ->assertStatus(422)->assertJsonPath('code', 'otp_invalid');
        }

        // Even the right (dev) code is refused once the code is locked.
        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '+966501212121', 'code' => '1234'])
            ->assertStatus(429)->assertJsonPath('code', 'otp_locked');
        $this->assertSame(5, OtpCode::where('identifier', '+966501212121')->value('attempts'));

        // A fresh code works exactly once.
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '0501212121'])->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '0501212121', 'code' => '1234'])->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '0501212121', 'code' => '1234'])
            ->assertStatus(422)->assertJsonPath('code', 'otp_expired');
    }

    public function test_otp_throttle_is_keyed_on_the_normalised_identifier(): void
    {
        // Same number in three spellings from ten different IPs shares one hourly budget of 10.
        $spellings = ['0501414141', '+966501414141', '501414141'];
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/v1/auth/otp/verify', ['identifier' => $spellings[$i % 3], 'code' => '0000'])
                ->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson('/api/v1/auth/otp/verify', ['identifier' => '966501414141', 'code' => '0000'])
            ->assertStatus(429);
    }

    public function test_otp_codes_are_hashed_and_tokens_expire(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '0501313131'])->assertOk();
        $otp = OtpCode::firstOrFail();
        $this->assertStringStartsWith('$2y$', $otp->code_hash);
        $this->assertArrayNotHasKey('code_hash', $otp->toArray());

        $token = $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '0501313131', 'code' => '1234'])->assertOk()->json('data.token');
        $pat = PersonalAccessToken::findToken($token);
        $this->assertNotNull($pat->expires_at);
        $this->assertTrue($pat->expires_at->between(now()->addDays(89), now()->addDays(91)));

        $this->travel(91)->days();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_pagination_is_capped(): void
    {
        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/orders?per_page=100000')->assertOk()->assertJsonPath('meta.per_page', 50);
        $this->getJson('/api/v1/notifications?per_page=100000')->assertOk()->assertJsonPath('meta.per_page', 50);
        $this->getJson('/api/v1/bookings?scope=all&per_page=100000')->assertOk()->assertJsonPath('meta.per_page', 50);
        $this->getJson('/api/v1/venues?per_page=1000')->assertStatus(422);
        $this->getJson('/api/v1/venues?sort=id;drop table users')->assertStatus(422);
    }

    public function test_forged_webhook_is_rejected_and_does_not_block_the_real_one(): void
    {
        $venue = $this->venue($this->provider());
        $customer = $this->customer();
        $orders = collect([app(OrderService::class)->createOnline($customer, [$this->line($venue, 1)])]);
        $payment = app(PaymentService::class)->checkout($customer, $orders);
        $body = json_encode(['id' => 'evt_x', 'type' => 'payment.paid', 'data' => ['reference' => $payment->reference]]);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        $this->call('POST', '/api/v1/payments/webhook', [], [], [], $headers + ['HTTP_X_SIGNATURE' => 'forged'], $body)->assertStatus(401);
        $this->call('POST', '/api/v1/payments/webhook', [], [], [], $headers, $body)->assertStatus(401);
        $this->assertSame('pending_payment', $orders->first()->fresh()->status->value);

        $this->call('POST', '/api/v1/payments/webhook', [], [], [], $headers + ['HTTP_X_SIGNATURE' => FakePaymentGateway::sign($body)], $body)
            ->assertOk()->assertJsonPath('duplicate', false);
        $this->assertSame('confirmed', $orders->first()->fresh()->status->value);
    }

    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }
}
