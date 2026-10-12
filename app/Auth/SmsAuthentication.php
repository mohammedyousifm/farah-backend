<?php

namespace App\Auth;

use App\Models\User;
use App\Services\Integrations\Contracts\SmsSender;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Contracts\HasBeforeChallengeHook;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Two-step login for the dashboards with a code sent by SMS to the person's own mobile number.
 * Sits beside Filament's e-mail codes: each person switches on SMS, e-mail, or both in "My account",
 * and with both on chooses at sign-in where the code should go.
 *
 * Same rules as the e-mail provider: a 6-digit code, hashed in the session against the user it was
 * issued for, valid a few minutes, two sends a minute, five tries on each form.
 */
class SmsAuthentication implements HasBeforeChallengeHook, MultiFactorAuthenticationProvider
{
    protected int $codeExpiryMinutes = 4;

    public function __construct(protected SmsSender $sms) {}

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'sms_code';
    }

    public function getLoginFormLabel(): string
    {
        return __('two_factor.sms.login_label');
    }

    public function isEnabled(Authenticatable $user): bool
    {
        return $user instanceof User && $user->has_sms_authentication && filled($user->phone);
    }

    /** Sends a fresh code. False when the person asked too often or has no number. */
    public function sendCode(User $user): bool
    {
        $key = 'sms-authentication:'.$user->getKey();

        if (blank($user->phone) || RateLimiter::tooManyAttempts($key, maxAttempts: 2)) {
            return false;
        }
        RateLimiter::hit($key);

        $code = $this->generateCode();
        session()->put($this->codeKey($user), Hash::make($code));
        session()->put($this->expiryKey($user), now()->addMinutes($this->codeExpiryMinutes)->timestamp);

        return $this->sms->send($user->phone, __('two_factor.sms.message', ['code' => $code, 'minutes' => $this->codeExpiryMinutes], $user->locale ?? 'ar'));
    }

    public function generateCode(): string
    {
        // A developer's machine sends no SMS: the fixed development code stands in, padded to six digits.
        if (app()->isLocal() && filled(config('farah.otp.dev_code'))) {
            return str_pad((string) config('farah.otp.dev_code'), 6, '0', STR_PAD_RIGHT);
        }

        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** A code only ever satisfies the account it was sent for. */
    public function verifyCode(#[SensitiveParameter] string $code, ?User $user = null): bool
    {
        $user ??= Filament::auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $hash = session($this->codeKey($user));
        $expires = session($this->expiryKey($user));

        if (blank($hash) || blank($expires) || now()->timestamp > (int) $expires || ! Hash::check($code, $hash)) {
            return false;
        }

        session()->forget([$this->codeKey($user), $this->expiryKey($user)]);

        return true;
    }

    public function beforeChallenge(Authenticatable $user): void
    {
        if ($user instanceof User) {
            $this->sendCode($user);
        }
    }

    /** The row in "My account": state, and the switch on / switch off buttons. */
    public function getManagementSchemaComponents(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return [
            Actions::make([
                $this->toggleAction(enable: true)->hidden(fn (): bool => $this->isEnabled($user)),
                $this->toggleAction(enable: false)->visible(fn (): bool => $this->isEnabled($user)),
            ])
                ->label(__('two_factor.sms.title'))
                ->belowContent(fn (): string => filled($user->phone)
                    ? __('two_factor.sms.description', ['phone' => self::mask($user->phone)])
                    : __('two_factor.sms.no_phone'))
                ->afterLabel(fn (): Text => $this->isEnabled($user)
                    ? Text::make(__('two_factor.enabled'))->badge()->color('success')
                    : Text::make(__('two_factor.disabled'))->badge()),
        ];
    }

    public function getChallengeFormComponents(Authenticatable $user): array
    {
        /** @var User $user */
        return [$this->codeInput($user, __('two_factor.sms.code_label', ['phone' => self::mask((string) $user->phone)]))];
    }

    /**
     * Switching SMS codes on or off. Both ask for a code first: on, to prove the number receives them;
     * off, so someone at an unlocked computer cannot remove the protection.
     */
    protected function toggleAction(bool $enable): Action
    {
        $name = $enable ? 'enable' : 'disable';

        return Action::make("{$name}SmsAuthentication")
            ->label(__("two_factor.sms.{$name}"))
            ->color($enable ? 'primary' : 'danger')
            ->icon($enable ? Heroicon::LockClosed : Heroicon::LockOpen)
            ->link()
            ->disabled(fn (): bool => blank(Filament::auth()->user()?->phone))
            ->mountUsing(function (Schema $schema): void {
                $schema->fill();
                $this->sendCode(Filament::auth()->user());
            })
            ->modalWidth(Width::Medium)
            ->modalHeading(__("two_factor.sms.{$name}_heading"))
            ->modalDescription(fn (): string => __('two_factor.sms.code_label', ['phone' => self::mask((string) Filament::auth()->user()?->phone)]))
            ->schema(fn (): array => [$this->codeInput(Filament::auth()->user())])
            ->beforeFormValidated(function () use ($name): void {
                $key = "sms-authentication-{$name}:".Filament::auth()->id();
                if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
                    throw ValidationException::withMessages(['mountedActions.0.data.code' => __('two_factor.too_many')]);
                }
                RateLimiter::hit($key);
            })
            ->modalSubmitActionLabel(__("two_factor.sms.{$name}"))
            ->action(function () use ($enable): void {
                Filament::auth()->user()->forceFill(['has_sms_authentication' => $enable])->save();

                Notification::make()->title(__($enable ? 'two_factor.sms.enabled_notice' : 'two_factor.sms.disabled_notice'))->success()->send();
            })
            ->rateLimit(5);
    }

    protected function codeInput(User $user, ?string $label = null): OneTimeCodeInput
    {
        return OneTimeCodeInput::make('code')
            ->label($label ?? __('two_factor.code'))
            ->validationAttribute(__('two_factor.code'))
            ->belowContent(Action::make('resend')
                ->label(__('two_factor.resend'))
                ->link()
                ->action(function () use ($user): void {
                    $sent = $this->sendCode($user);

                    Notification::make()->title(__($sent ? 'two_factor.resent' : 'two_factor.too_many'))->{$sent ? 'success' : 'danger'}()->send();
                }))
            ->required()
            ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($user): void {
                if (! is_string($value) || ! $this->verifyCode($value, $user)) {
                    $fail(__('two_factor.invalid'));
                }
            });
    }

    /** "+966 5•• ••• •67": enough to recognise the number, not enough to copy it from the screen. */
    public static function mask(string $phone): string
    {
        $masked = strlen($phone) < 6 ? $phone : substr($phone, 0, 5).str_repeat('•', max(0, strlen($phone) - 7)).substr($phone, -2);

        // Left-to-right isolate marks: inside Arabic text the number would otherwise read backwards.
        return "\u{2066}{$masked}\u{2069}";
    }

    protected function codeKey(User $user): string
    {
        return 'sms_authentication_code:'.$user->getKey();
    }

    protected function expiryKey(User $user): string
    {
        return 'sms_authentication_code_expires_at:'.$user->getKey();
    }
}
