<?php

namespace App\Filament\Pages\Auth;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

/**
 * Admin sign-in. AUTH-ADMIN-003 policy: no "Remember me" for the admin panel. An admin session ends when
 * the browser is closed, after 30 minutes without activity, 12 hours after sign-in, or on sign-out
 * (EnforceSessionTimeout, config farah.admin_session).
 */
class AdminLogin extends Login
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
        ]);
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()->hidden();
    }
}
