<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\OtpService;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
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
        return TextInput::make('phone')
            ->label(__('two_factor.phone'))
            ->tel()
            ->placeholder('05XXXXXXXX')
            ->helperText(__('two_factor.phone_hint'))
            ->regex('/^(\+?966|0)?5\d{8}$/')
            ->dehydrateStateUsing(fn (?string $state) => filled($state) ? OtpService::normalize($state) : null)
            // Compared in its stored form (+9665...), whatever way it was typed.
            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                $phone = filled($value) ? OtpService::normalize((string) $value) : null;
                if ($phone && User::withTrashed()->where('phone', $phone)->whereKeyNot($this->getUser()->getKey())->exists()) {
                    $fail(__('two_factor.phone_taken'));
                }
            })
            ->extraInputAttributes(['dir' => 'ltr']);
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
