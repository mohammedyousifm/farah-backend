<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;

/**
 * Dashboard sign-in (both panels). AUTH-ADMIN-001: the typed password never travels back to the browser.
 *
 * Filament keeps the form state (including the password) in the Livewire snapshot it returns after
 * every attempt. Here it is wiped before the reply is built, whatever the outcome. When a two-step code
 * is asked for, Filament checks the password again after the code, so for that short step it waits
 * encrypted in the server-side session instead of in the page, and is removed as soon as the step ends.
 */
class Login extends BaseLogin
{
    private const PENDING = 'auth.pending_login_password';

    public function authenticate(): ?LoginResponse
    {
        if ($this->awaitingSecondStep() && blank($this->data['password'] ?? null) && session()->has(self::PENDING)) {
            $this->data['password'] = decrypt(session()->get(self::PENDING));
        }

        $signedIn = false;

        try {
            $response = parent::authenticate();
            $signedIn = $response !== null;

            return $response;
        } finally {
            if (! $signedIn && $this->awaitingSecondStep() && filled($this->data['password'] ?? null)) {
                session()->put(self::PENDING, encrypt((string) $this->data['password']));
            } elseif (! $this->awaitingSecondStep() || $signedIn) {
                session()->forget(self::PENDING);
            }

            $this->data['password'] = null;
        }
    }

    private function awaitingSecondStep(): bool
    {
        return filled($this->userUndertakingMultiFactorAuthentication);
    }
}
