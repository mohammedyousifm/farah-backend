<?php

namespace App\Filament\Pages\Auth;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Timebox;
use SensitiveParameter;

/**
 * "Forgot password" (AUTH-ADMIN-005). The public answer is always the same "if this address has an account,
 * a link is on its way", whether the address is registered, unknown, not allowed in this panel, or asked again
 * too soon. Only an eligible account actually gets the e-mail. The e-mail is sent after the reply and the
 * reply is padded to a fixed time, so neither the wording nor the timing tells the addresses apart.
 * The page-level limit (2 requests a minute from one browser) still shows its own message, which says
 * nothing about the address.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    /** Every answer takes at least this long (microseconds). */
    private const MIN_RESPONSE_MICROSECONDS = 600_000;

    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();
        $panel = Filament::getCurrentOrDefaultPanel();

        app(Timebox::class)->call(function () use ($data, $panel): void {
            Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
                $this->getCredentialsFromFormData($data),
                function (CanResetPassword $user, #[SensitiveParameter] string $token) use ($panel): void {
                    if ($user instanceof FilamentUser && ! $user->canAccessPanel($panel)) {
                        return;
                    }

                    $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                    $notification->url = Filament::getResetPasswordUrl($token, $user);

                    // After the reply: the mail server's speed must not reveal that the address exists.
                    defer(function () use ($user, $notification): void {
                        $user->notify($notification);
                        event(new PasswordResetLinkSent($user));
                    });
                },
            );
        }, self::MIN_RESPONSE_MICROSECONDS);

        // Same words for every address (registered, unknown, throttled per address, or not for this panel).
        $this->getSentNotification(Password::RESET_LINK_SENT)
            ?->title(__('provider.password_reset.sent_generic'))
            ->send();

        $this->form->fill();
    }
}
