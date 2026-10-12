<?php

namespace Tests\Feature\Admin;

use App\Auth\SmsAuthentication;
use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use App\Services\Integrations\Contracts\SmsSender;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/** Dashboard two-step login: a code by SMS, by e-mail, or both, switched on by each person. */
class TwoStepLoginTest extends AdminTestCase
{
    /** @var array<int, array{0: string, 1: string}> phone, message */
    private array $texts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsSender::class, new class($this->texts) implements SmsSender
        {
            public function __construct(private array &$sent) {}

            public function send(string $phone, string $message): bool
            {
                $this->sent[] = [$phone, $message];

                return true;
            }
        });
    }

    private function lastCode(): string
    {
        preg_match('/\b(\d{6})\b/', end($this->texts)[1], $m);

        return $m[1];
    }

    public function test_both_dashboards_offer_sms_and_email_codes(): void
    {
        foreach (['admin', 'provider'] as $panel) {
            $ids = array_map(fn ($provider) => $provider->getId(), Filament::getPanel($panel)->getMultiFactorAuthenticationProviders());
            $this->assertEqualsCanonicalizing(['sms_code', 'email_code'], array_values($ids), $panel);
        }
    }

    public function test_sms_codes_need_a_number_reach_only_that_number_and_work_once(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $sms = SmsAuthentication::make();

        // No number yet: nothing can be sent, nothing is enabled.
        $admin->forceFill(['phone' => null, 'has_sms_authentication' => true])->save();
        $this->assertFalse($sms->sendCode($admin));
        $this->assertFalse($sms->isEnabled($admin));

        $admin->forceFill(['phone' => '+966501112233'])->save();
        $this->assertTrue($sms->isEnabled($admin));
        $this->assertTrue($sms->sendCode($admin));
        $this->assertSame('+966501112233', $this->texts[0][0]);
        $code = $this->lastCode();

        $this->assertFalse($sms->verifyCode($code === '000000' ? '111111' : '000000', $admin));
        // A code issued for one account never opens another.
        $this->assertFalse($sms->verifyCode($code, $this->otherAdmin()));
        $this->assertTrue($sms->verifyCode($code, $admin));
        $this->assertFalse($sms->verifyCode($code, $admin), 'a code works once');

        // Expired codes are refused.
        $sms->sendCode($admin);
        $this->travel(5)->minutes();
        $this->assertFalse($sms->verifyCode($this->lastCode(), $admin));

        // Two sends a minute at most.
        $this->travel(2)->minutes();
        $this->assertTrue($sms->sendCode($admin));
        $this->assertTrue($sms->sendCode($admin));
        $this->assertFalse($sms->sendCode($admin));
    }

    public function test_email_codes_are_switched_on_per_person(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();
        $email = EmailAuthentication::make();

        $this->assertFalse($email->isEnabled($admin));
        $email->sendCode($admin);
        Notification::assertSentTo($admin, VerifyEmailAuthentication::class);

        $admin->toggleEmailAuthentication(true);
        $this->assertTrue($email->isEnabled($admin->fresh()));
        // One on, the other still off: each channel is its own choice.
        $this->assertFalse(SmsAuthentication::make()->isEnabled($admin->fresh()));
    }

    public function test_my_account_saves_the_number_and_a_new_number_switches_sms_codes_off(): void
    {
        $admin = $this->superAdmin();
        $admin->forceFill(['phone' => '+966501112233', 'has_sms_authentication' => true])->save();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditProfile::class)
            ->assertSee(__('two_factor.sms.title'))
            ->fillForm(['phone' => '0559998877'])
            ->call('save')
            ->assertHasNoFormErrors();

        $admin->refresh();
        $this->assertSame('+966559998877', $admin->phone);
        $this->assertFalse($admin->has_sms_authentication, 'the new number has not proved it receives codes');

        // A number that belongs to someone else is refused.
        $this->otherAdmin()->forceFill(['phone' => '+966500000009'])->save();
        Livewire::test(EditProfile::class)->fillForm(['phone' => '0500000009'])->call('save')->assertHasFormErrors(['phone']);
    }

    private function otherAdmin(): User
    {
        return User::firstOrCreate(['email' => 'second@farah.test'], ['name' => 'Second', 'password' => 'password-1', 'type' => 'admin', 'status' => 'active']);
    }
}
