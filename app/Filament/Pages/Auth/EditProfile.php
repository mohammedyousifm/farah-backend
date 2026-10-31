<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Support\SaudiInput;
use App\Models\User;
use App\Services\OtpService;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use SensitiveParameter;

/**
 * "My account" in both dashboards: name, e-mail, mobile number and password, then (below the form)
 * two-step login by SMS, by e-mail, or both.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getPhoneFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    protected function getPhoneFormComponent(): Component
    {
        return SaudiInput::mobile()
            ->label(__('two_factor.phone'))
            ->helperText(__('two_factor.phone_hint'))
            // Compared in its stored form (+9665...), whatever way it was typed.
            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                $phone = filled($value) ? OtpService::normalize((string) $value) : null;
                if ($phone && User::withTrashed()->where('phone', $phone)->whereKeyNot($this->getUser()->getKey())->exists()) {
                    $fail(__('two_factor.phone_taken'));
                }
            });
    }

    /**
     * The mobile number signs the person in to the app by SMS code (and acts for their stores there), so changing it
     * asks for the current password, like a new e-mail does: someone at an unlocked computer cannot move it to theirs.
     */
    protected function getCurrentPasswordFormComponent(): Component
    {
        $phoneChanged = function (Get $get): bool {
            $phone = filled($get('phone')) ? OtpService::normalize((string) $get('phone')) : null;

            return $phone !== $this->getUser()->getAttributeValue('phone');
        };

        $component = parent::getCurrentPasswordFormComponent();

        return $component->visible(fn (Get $get): bool => filled($get('password'))
            || $get('email') !== $this->getUser()->getAttributeValue('email')
            || $phoneChanged($get));
    }

    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        $data = parent::mutateFormDataBeforeSave($data);

        // A new number has not proved it receives codes: SMS codes go off until switched on again.
        if (array_key_exists('phone', $data) && $data['phone'] !== $this->getUser()->phone) {
            $data['has_sms_authentication'] = false;
        }

        return $data;
    }
}
