<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\IntegrationLogs\IntegrationLogResource;
use App\Livewire\AdminNotifications;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Integrations\IntegrationLogger;
use App\Support\AdminNotifier;
use App\Support\PayloadTree;
use App\Support\Redactor;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;

/** TODAY-04 (clickable, archived, de-duplicated admin bell) and TODAY-05 (Integration Logs payload view). */
class AdminBellAndLogsTest extends AdminTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->superAdmin(email: 'admin@farah.sa');
        $this->world();
        $this->actingAs($this->admin);
        $this->usePanel();
    }

    private function complaintNotice(): DatabaseNotification
    {
        AdminNotifier::send(['support'], 'admin_complaint_new', ['number' => 'CMP-TEST-1', 'subject' => 'Late'], ['complaint_id' => $this->complaint->id]);

        return $this->admin->notifications()->where('data->key', 'admin_complaint_new')->firstOrFail();
    }

    public function test_the_same_event_twice_rings_the_bell_once(): void
    {
        $this->complaintNotice();
        AdminNotifier::send(['support'], 'admin_complaint_new', ['number' => 'CMP-TEST-1', 'subject' => 'Late'], ['complaint_id' => $this->complaint->id]);

        $this->assertSame(1, $this->admin->notifications()->where('data->key', 'admin_complaint_new')->count());
    }

    public function test_open_marks_read_and_goes_to_the_exact_record(): void
    {
        $notice = $this->complaintNotice();

        $this->get(route('filament.admin.notifications.open', $notice->id))
            ->assertRedirect(ComplaintResource::getUrl('view', ['record' => $this->complaint]));
        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_open_falls_back_when_the_record_was_deleted(): void
    {
        $notice = $this->complaintNotice();
        $this->complaint->delete();

        $this->get(route('filament.admin.notifications.open', $notice->id))->assertRedirect(url('/admin'));
    }

    public function test_open_is_safe_for_other_admins_and_without_permission(): void
    {
        $notice = $this->complaintNotice();

        // Someone else's notification id is not found.
        $this->actingAs($this->superAdmin('finance', 'fin@farah.test'))
            ->get(route('filament.admin.notifications.open', $notice->id))->assertNotFound();

        // An admin whose role cannot see complaints lands on the dashboard, not on the record.
        $finance = User::where('email', 'fin@farah.test')->first();
        $this->actingAs($finance);
        $finance->notify(new SystemNotification('admin_complaint_new', [], ['complaint_id' => $this->complaint->id]));
        $this->get(route('filament.admin.notifications.open', $finance->notifications()->first()->id))->assertRedirect(url('/admin'));
    }

    public function test_clear_and_close_archive_instead_of_deleting(): void
    {
        $notice = $this->complaintNotice();

        Livewire::test(AdminNotifications::class)
            ->assertSee(__('admin.bell.open'))
            ->call('removeNotification', $notice->id);
        $this->assertNotNull($notice->fresh()?->dismissed_at); // the row is still there

        Livewire::test(AdminNotifications::class)->assertDontSee('CMP-TEST-1');

        AdminNotifier::send(['support'], 'admin_provider_pending', ['name' => 'X'], ['provider_id' => $this->provider->id]);
        Livewire::test(AdminNotifications::class)->call('clearNotifications');
        $this->assertSame(0, $this->admin->notifications()->where('data->format', 'filament')->whereNull('dismissed_at')->count());
        $this->assertGreaterThanOrEqual(2, $this->admin->notifications()->whereNotNull('dismissed_at')->count());
    }

    public function test_integration_payloads_are_redacted_and_readable(): void
    {
        IntegrationLogger::call('payment', 'create_checkout', ['reference' => 'R1', 'api_key' => 'sk_live_123', 'card' => ['cvv' => '123']], fn () => [
            'id' => 'pay_1',
            'checkout_url' => 'https://pay.example.com/c/1?ref=R1&signature=abcdef',
            'token' => 'tok_secret',
            'long' => str_repeat('x', 300),
        ]);
        $log = IntegrationLog::latest('id')->firstOrFail();

        $this->assertSame(Redactor::MASK, $log->request['api_key']);
        $this->assertSame(Redactor::MASK, $log->request['card']['cvv']);
        $this->assertSame(Redactor::MASK, $log->response['token']);
        $this->assertStringContainsString('signature='.Redactor::MASK, $log->response['checkout_url']);
        $this->assertStringContainsString('ref=R1', $log->response['checkout_url']);

        // Older rows written before redaction are masked on display too; malformed/empty payloads do not break the page.
        $old = IntegrationLog::create(['service' => 'sms', 'action' => 'send', 'request' => ['password' => 'plain-secret'], 'response' => [], 'success' => false, 'duration_ms' => 0]);
        $broken = IntegrationLog::create(['service' => 'sms', 'action' => 'send', 'request' => null, 'response' => null, 'success' => true, 'duration_ms' => null]);
        // MariaDB refuses invalid JSON in the column, so a malformed value is checked on the decoder itself.
        $this->assertSame(['{not json', false], PayloadTree::decode('{not json'));
        $deep = [];
        $ref = &$deep;
        for ($i = 0; $i < 20; $i++) {
            $ref['a'] = [];
            $ref = &$ref['a'];
        }
        unset($ref);
        $nested = IntegrationLog::create(['service' => 'push', 'action' => 'send', 'request' => ['x' => 1], 'response' => $deep, 'success' => true, 'duration_ms' => 1500]);

        $this->get(IntegrationLogResource::getUrl('view', ['record' => $log]))->assertOk()
            ->assertSee(__('admin.payload.structured'))->assertSee(__('admin.payload.copy_json'))->assertSee(__('admin.payload.show_full'))
            ->assertDontSee('sk_live_123')->assertDontSee('tok_secret')->assertDontSee('abcdef');
        $this->get(IntegrationLogResource::getUrl('view', ['record' => $old]))->assertOk()
            ->assertDontSee('plain-secret')->assertSee('<1 ms')->assertSee(__('admin.payload.failed'));
        $this->get(IntegrationLogResource::getUrl('view', ['record' => $broken]))->assertOk()
            ->assertSee(__('admin.payload.empty'))->assertSee(__('admin.payload.not_recorded'));
        $this->get(IntegrationLogResource::getUrl('view', ['record' => $nested]))->assertOk()->assertSee('1.50 s');
    }
}
