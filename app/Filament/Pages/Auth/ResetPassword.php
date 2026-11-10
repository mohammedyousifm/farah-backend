<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;

/** "Choose a new password" from the e-mailed link. AUTH-ADMIN-001: the typed passwords are not sent back to the browser. */
class ResetPassword extends BaseResetPassword
{
    public function resetPassword(): ?PasswordResetResponse
    {
        try {
            return parent::resetPassword();
        } finally {
            $this->password = null;
            $this->passwordConfirmation = null;
        }
    }
}
