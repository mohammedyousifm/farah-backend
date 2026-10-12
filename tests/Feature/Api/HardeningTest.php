<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\ProviderService;
use App\Support\Like;
use App\Support\SchedulerHeartbeat;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/** The protections and housekeeping added in the hardening pass. */
class HardeningTest extends ApiTestCase
{
    public function test_search_text_cannot_act_as_a_wildcard(): void
    {
        $this->assertSame('%50\%\_off%', Like::contains('50%_off'));

        $this->venue($this->provider(), ['name_ar' => 'صالة العليا', 'name_en' => 'Olaya Hall']);
        $this->getJson('/api/v1/venues?q=Olaya')->assertOk()->assertJsonCount(1, 'data');
        // "%" used to match every venue.
        $this->getJson('/api/v1/venues?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/venues?q=_____')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_every_response_carries_a_request_id_and_private_answers_are_never_cached(): void
    {
        $public = $this->getJson('/api/v1/cities')->assertOk()->assertHeader('X-Request-Id')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringNotContainsString('no-store', (string) $public->headers->get('Cache-Control'));

        // A caller's own well-formed id is kept, so the app and the server logs can be matched.
        $this->getJson('/api/v1/cities', ['X-Request-Id' => 'app-12345678'])->assertHeader('X-Request-Id', 'app-12345678');
        $forged = $this->getJson('/api/v1/cities', ['X-Request-Id' => "bad id\nwith a new line"])->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f\-]{36}$/', (string) $forged);

        Sanctum::actingAs($this->customer());
        $private = $this->getJson('/api/v1/me')->assertOk();
        $this->assertStringContainsString('no-store', (string) $private->headers->get('Cache-Control'));
    }

    public function test_unchanged_public_lists_answer_not_modified(): void
    {
        $first = $this->getJson('/api/v1/cities')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->getJson('/api/v1/cities', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_admins_are_told_when_a_provider_registers(): void
    {
        Notification::fake();
        $operations = $this->admin('operations');
        $finance = $this->admin('finance');
        $owner = $this->customer('+966509998877');

        app(ProviderService::class)->register($owner, ['type' => 'establishment', 'name_ar' => 'صالة جديدة', 'name_en' => 'New Hall', 'cr_number' => '1010000001']);

        Notification::assertSentTo($operations, SystemNotification::class, fn (SystemNotification $n) => $n->key === 'admin_provider_pending');
        Notification::assertNotSentTo($finance, SystemNotification::class);
    }

    public function test_a_notification_is_stored_for_the_app_and_for_the_dashboard_bell(): void
    {
        $customer = $this->customer();
        SystemNotification::send($customer, 'review_prompt', ['venue' => 'صالة العليا'], ['order_id' => 5]);

        $data = $customer->notifications()->first()->data;
        $this->assertSame('review_prompt', $data['key']);           // what the app reads
        $this->assertSame('filament', $data['format']);             // what the dashboard bell reads
        $this->assertSame('كيف كانت تجربتك؟', $data['title']);
    }

    public function test_daily_housekeeping_removes_only_what_is_stale(): void
    {
        OtpCode::create(['identifier' => '+966500000001', 'channel' => 'sms', 'purpose' => 'login', 'code_hash' => Hash::make('1'), 'expires_at' => now()->subDays(3)]);
        OtpCode::create(['identifier' => '+966500000002', 'channel' => 'sms', 'purpose' => 'login', 'code_hash' => Hash::make('1'), 'expires_at' => now()->addMinutes(5)]);
        $customer = $this->customer();
        SystemNotification::send($customer, 'review_prompt', ['venue' => 'x']);
        $customer->notifications()->update(['created_at' => now()->subDays(200)]); // old but unread: kept
        AuditLog::record('test.kept');

        $this->artisan('farah:prune')->assertSuccessful();

        $this->assertSame(['+966500000002'], OtpCode::pluck('identifier')->all());
        $this->assertSame(1, $customer->notifications()->count());
        $this->assertSame(1, AuditLog::where('action', 'test.kept')->count());
    }

    public function test_doctor_reports_an_unsafe_configuration(): void
    {
        Cache::forget('farah.scheduler.heartbeat');

        $this->artisan('farah:doctor')->expectsOutputToContain('Scheduler running')->assertSuccessful();

        // The signal the scheduler writes every minute is read back correctly, fresh and stale.
        $this->assertFalse(SchedulerHeartbeat::isFresh());
        $this->artisan('schedule:run')->assertSuccessful();
        $this->assertTrue(SchedulerHeartbeat::isFresh());
        $this->travel(11)->minutes();
        $this->assertFalse(SchedulerHeartbeat::isFresh());
        Cache::put(SchedulerHeartbeat::KEY, 'not a time', 60); // whatever is stored, reading it never throws
        $this->assertFalse(SchedulerHeartbeat::isFresh());
        // The same findings stop a deployment script.
        $this->artisan('farah:doctor --strict')->assertFailed();
    }

    private function admin(string $role): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::create(['name' => $role, 'email' => "{$role}@farah.test", 'password' => 'password-1', 'type' => 'admin', 'status' => 'active']);
        $admin->syncRoles([$role]);

        return $admin;
    }
}
