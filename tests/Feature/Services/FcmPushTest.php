<?php

namespace Tests\Feature\Services;

use App\Models\DeviceToken;
use App\Notifications\SystemNotification;
use App\Services\Integrations\Contracts\PushSender;
use App\Services\Integrations\Drivers\FcmPushSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** PUSH_DRIVER=fcm: notifications go to Firebase with a signed Google token; dead devices are forgotten. */
class FcmPushTest extends ServiceTestCase
{
    private function useFirebase(): void
    {
        // A throwaway key that exists only for this test; it belongs to no Firebase project.
        $pem = file_get_contents(base_path('tests/Fixtures/firebase-test-key.pem'));
        $path = storage_path('framework/testing/firebase-test.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode(['project_id' => 'farah-test', 'client_email' => 'push@farah-test.iam.gserviceaccount.com', 'private_key' => $pem]));

        config(['farah.integrations.push.driver' => 'fcm', 'farah.integrations.push.credentials' => $path]);
        $this->app->forgetInstance(PushSender::class);
    }

    public function test_a_notification_reaches_firebase_for_every_device_and_dead_devices_are_removed(): void
    {
        $this->useFirebase();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => fn (Request $request) => $request['message']['token'] === 'gone'
                ? Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                : Http::response(['name' => 'projects/farah-test/messages/1']),
        ]);

        $customer = $this->customer();
        $customer->forceFill(['locale' => 'ar'])->save();
        $customer->deviceTokens()->create(['token' => 'phone-a', 'platform' => 'android']);
        $customer->deviceTokens()->create(['token' => 'gone', 'platform' => 'android']);

        SystemNotification::send($customer, 'review_prompt', ['venue' => 'كيو لاونج'], ['action' => 'review', 'order_id' => 77, 'booking_id' => 5]);
        $this->app->make('Illuminate\Support\Defer\DeferredCallbackCollection')->invoke();

        $this->assertInstanceOf(FcmPushSender::class, app(PushSender::class));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com') && str_starts_with((string) $r['assertion'], 'eyJ'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'projects/farah-test/messages:send')
            && $r->hasHeader('Authorization', 'Bearer google-token')
            && $r['message']['token'] === 'phone-a'
            && $r['message']['notification']['title'] === 'كيف كانت تجربتك؟'
            // Everything the app needs to open the right screen, all as strings.
            && $r['message']['data'] === ['action' => 'review', 'order_id' => '77', 'booking_id' => '5', 'key' => 'review_prompt']);

        $this->assertSame(['phone-a'], DeviceToken::pluck('token')->all());
        // The notification is also kept for the in-app list.
        $this->assertSame(1, $customer->notifications()->count());
    }

    public function test_a_missing_credentials_file_never_breaks_the_action(): void
    {
        config(['farah.integrations.push.driver' => 'fcm', 'farah.integrations.push.credentials' => storage_path('nowhere.json')]);
        $this->app->forgetInstance(PushSender::class);
        Http::fake();

        $this->assertSame(0, app(PushSender::class)->send(['phone-a'], 'title', 'body'));
        Http::assertNothingSent();
    }
}
