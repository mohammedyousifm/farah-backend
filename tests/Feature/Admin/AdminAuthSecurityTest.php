<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Auth\AdminLogin;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Http\Middleware\EnforceSessionTimeout;
use App\Models\Provider;
use App\Models\User;
use App\Services\Integrations\Contracts\SmsSender;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/** Admin security assessment 2026-11-09: AUTH-ADMIN-001 … 005. */
class AdminAuthSecurityTest extends AdminTestCase
{
    private const SECRET = 'Very$ecret-Passw0rd!';

    private function boss(): User
    {
        $admin = $this->superAdmin(email: 'boss@farah.test');
        $admin->forceFill(['password' => self::SECRET])->save();

        return $admin;
    }

    /** The JSON Livewire would send back to the browser (snapshot + rendered HTML). */
    private function reply($component): string
    {
        return json_encode($component->snapshot).$component->html();
    }

    // ------------------------------------------------------------ AUTH-ADMIN-001

    public function test_login_never_sends_the_password_back_on_success_or_failure(): void
    {
        $this->usePanel();
        $this->boss();

        $failed = Livewire::test(AdminLogin::class)
            ->fillForm(['email' => 'boss@farah.test', 'password' => self::SECRET.'x'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertNull($failed->get('data.password'));
        $this->assertStringNotContainsString(self::SECRET, $this->reply($failed));

        $ok = Livewire::test(AdminLogin::class)
            ->fillForm(['email' => 'boss@farah.test', 'password' => self::SECRET])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();
        $this->assertNull($ok->get('data.password'));
        $this->assertStringNotContainsString(self::SECRET, $this->reply($ok));
        $this->assertAuthenticated();
    }

    public function test_two_step_login_keeps_the_password_off_the_page_and_still_works(): void
    {
        // The panel's SMS provider was built at boot: give it a fake sender that keeps the texts.
        $sender = new class implements SmsSender
        {
            public array $sent = [];

            public function send(string $phone, string $message): bool
            {
                $this->sent[] = $message;

                return true;
            }
        };
        $sms = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['sms_code'];
        (new \ReflectionProperty($sms, 'sms'))->setValue($sms, $sender);
        $this->usePanel();
        $admin = $this->boss();
        $admin->forceFill(['phone' => '+966501112299', 'has_sms_authentication' => true])->save();

        $page = Livewire::test(AdminLogin::class)
            ->fillForm(['email' => 'boss@farah.test', 'password' => self::SECRET])
            ->call('authenticate');
        $this->assertGuest();
        $this->assertNull($page->get('data.password'));
        $this->assertStringNotContainsString(self::SECRET, $this->reply($page));
        $this->assertStringNotContainsString(self::SECRET, (string) session('auth.pending_login_password')); // encrypted

        preg_match('/\b(\d{6})\b/', (string) end($sender->sent), $m);
        $page->set('data.multiFactor.sms_code.code', $m[1])->call('authenticate')->assertRedirect();
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('auth.pending_login_password'));
        $this->assertStringNotContainsString(self::SECRET, $this->reply($page));
    }

    public function test_my_account_empties_passwords_even_when_the_save_is_refused(): void
    {
        $this->usePanel();
        $this->actingAs($this->boss());

        $page = Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'N3w-Passw0rd!x', 'passwordConfirmation' => 'different', 'currentPassword' => self::SECRET])
            ->call('save');
        $this->assertNull($page->get('data.currentPassword'));
        $this->assertNull($page->get('data.password'));
        $this->assertStringNotContainsString(self::SECRET, $this->reply($page));
    }

    // ------------------------------------------------------------ AUTH-ADMIN-003

    public function test_admin_login_has_no_remember_me_and_sessions_end_with_the_browser(): void
    {
        $this->usePanel();
        Livewire::test(AdminLogin::class)->assertFormFieldDoesNotExist('remember');
        $this->assertTrue(config('session.expire_on_close'));

        // Logout ends the server-side session.
        $this->actingAs($this->boss());
        $this->post(Filament::getPanel('admin')->getLogoutUrl())->assertRedirect();
        $this->assertGuest();
        $this->get('/admin')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    }

    // ------------------------------------------------------------ AUTH-ADMIN-004

    public function test_idle_and_absolute_limits_are_enforced_on_the_server_and_polling_is_not_activity(): void
    {
        $this->actingAs($this->boss());
        $this->get('/admin')->assertOk();

        // 20 minutes later a real page load: still signed in, and it counts as activity.
        $this->travel(20)->minutes();
        $this->get('/admin')->assertOk();

        // Background polling every few seconds for 40 minutes does not keep the session alive.
        $poll = ['components' => [['snapshot' => '{}', 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => ['type' => 'poll']]]]]];
        $this->assertTrue(EnforceSessionTimeout::isBackground(Request::create('/livewire/update', 'POST', $poll, server: ['HTTP_X_LIVEWIRE' => '1'])));
        $click = ['components' => [['snapshot' => '{}', 'updates' => [], 'calls' => [['method' => 'save', 'params' => [], 'metadata' => []]]]]];
        $this->assertFalse(EnforceSessionTimeout::isBackground(Request::create('/livewire/update', 'POST', $click, server: ['HTTP_X_LIVEWIRE' => '1'])));
        $typed = ['components' => [['snapshot' => '{}', 'updates' => ['data.name' => 'x'], 'calls' => [['method' => '$refresh', 'metadata' => ['type' => 'poll']]]]]];
        $this->assertFalse(EnforceSessionTimeout::isBackground(Request::create('/livewire/update', 'POST', $typed, server: ['HTTP_X_LIVEWIRE' => '1'])));

        $this->travel(31)->minutes();
        $this->get('/admin')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
        $this->assertGuest();
    }

    public function test_absolute_limit_signs_out_even_an_active_admin(): void
    {
        $this->actingAs($this->boss());
        $this->get('/admin')->assertOk();
        for ($i = 0; $i < 25; $i++) { // active every 29 minutes for 12+ hours
            $this->travel(29)->minutes();
            $response = $this->get('/admin');
        }
        $response->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
        $this->assertGuest();
    }

    // ------------------------------------------------------------ AUTH-ADMIN-005

    public function test_password_reset_gives_the_same_answer_for_known_and_unknown_addresses(): void
    {
        Notification::fake();
        $this->withoutDefer();
        Filament::setCurrentPanel(Filament::getPanel('provider'));
        $this->world();
        $owner = Provider::query()->firstOrFail()->owner;
        $owner->forceFill(['email' => 'owner-reset@farah.test'])->save();
        $generic = __('provider.password_reset.sent_generic');

        foreach ([$owner->email, 'nobody-here@farah.test', 'boss@farah.test'] as $email) {
            $page = Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $email])->call('request');
            $page->assertNotified($generic);
            $this->assertStringNotContainsString(__('passwords.user'), $this->reply($page));
            $this->travel(2)->minutes(); // past the page's own 2-a-minute limit
        }

        Notification::assertSentTo($owner, ResetPassword::class);
        Notification::assertSentTimes(ResetPassword::class, 1); // the unknown address and the admin got nothing
    }

    // ------------------------------------------------------------ AUTH-ADMIN-002

    public function test_dashboard_policy_blocks_inline_handlers_and_reports_the_target_policy(): void
    {
        $response = $this->get('/admin/login')->assertOk();
        $enforced = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src-attr 'none'", $enforced);
        $this->assertStringContainsString("object-src 'none'", $enforced);
        $target = (string) $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringNotContainsString("'unsafe-inline'", explode('; style-src', $target)[0]);
        $this->assertStringContainsString("'nonce-", $target);
        $this->assertStringContainsString('report-uri /csp-report', $target);

        $this->postJson('/csp-report', ['csp-report' => ['violated-directive' => 'script-src', 'blocked-uri' => 'inline', 'document-uri' => 'https://x/admin?a=1']])
            ->assertNoContent();

        // Public pages keep their nonce-only policy.
        $this->assertStringNotContainsString("'unsafe-inline' 'unsafe-eval'", (string) $this->get('/')->headers->get('Content-Security-Policy'));
    }

    public function test_provider_login_uses_the_same_password_wiping_page(): void
    {
        $this->assertSame(Login::class, Filament::getPanel('provider')->getLoginRouteAction());
    }
}
