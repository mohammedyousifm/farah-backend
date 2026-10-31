<?php

namespace App\Filament\Support;

use App\Rules\SaudiIban;
use App\Services\OtpService;
use Filament\Forms\Components\TextInput;

/**
 * Saudi mobile and IBAN fields for the dashboards. Both clean the value while it is typed (Arabic digits become
 * Latin ones, spaces and other characters drop out, typing stops at the full length), check it on the server
 * in the same cleaned form, and save it normalised: mobiles as +9665XXXXXXXX, IBANs as SA + 22 digits.
 */
class SaudiInput
{
    /** Turns Arabic-Indic digits into 0-9 and keeps the digits only (JS, run on every keystroke). */
    private const JS_DIGITS = "\$el.value.replace(/[\u{0660}-\u{0669}]/g, d => d.charCodeAt(0) - 0x660).replace(/[\u{06F0}-\u{06F9}]/g, d => d.charCodeAt(0) - 0x6F0)";

    /** Writes the cleaned value back and lets Livewire see it (once: the second pass changes nothing). */
    private const JS_APPLY = "if (v !== \$el.value) { \$el.value = v; \$el.dispatchEvent(new Event('input')) }";

    public static function mobile(string $name = 'phone'): TextInput
    {
        return TextInput::make($name)->tel()
            ->placeholder('05XXXXXXXX')
            ->formatStateUsing(fn (?string $state) => self::localMobile($state))
            ->extraInputAttributes([
                'dir' => 'ltr',
                'inputmode' => 'numeric',
                'autocomplete' => 'tel-national',
                // 05 then eight digits: a leading 5 or a pasted +966 becomes 05, anything else that cannot start
                // a Saudi mobile is refused, and nothing is taken past the tenth digit.
                'x-on:input' => 'let v = '.self::JS_DIGITS.".replace(/\\D/g, ''); "
                    ."if (v.startsWith('9665')) v = '0' + v.slice(3); if (v.startsWith('5')) v = '0' + v; "
                    ."while (v && !'05'.startsWith(v.slice(0, 2))) v = v.slice(0, -1); v = v.slice(0, 10); ".self::JS_APPLY,
            ])
            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && ! self::isMobile((string) $value)) {
                    $fail(__('validation.custom.mobile.format'));
                }
            })
            ->dehydrateStateUsing(fn (?string $state) => filled($state) ? OtpService::normalize($state) : null);
    }

    public static function iban(string $name = 'iban'): TextInput
    {
        return TextInput::make($name)
            ->placeholder('SA0000000000000000000000')
            ->extraInputAttributes([
                'dir' => 'ltr',
                'autocomplete' => 'off',
                'spellcheck' => 'false',
                // SA then 22 digits; spaces from a copied "SA03 8000 ..." drop out, lower case becomes upper case.
                'x-on:input' => 'let v = '.self::JS_DIGITS.".toUpperCase().replace(/[^A-Z0-9]/g, ''); "
                    ."if (/^\\d/.test(v)) v = 'SA' + v; v = v.slice(0, 2).replace(/[^A-Z]/g, '') + v.slice(2).replace(/\\D/g, ''); "
                    .'v = v.slice(0, 24); '.self::JS_APPLY,
            ])
            ->rule(new SaudiIban)
            ->dehydrateStateUsing(fn (?string $state) => filled($state) ? SaudiIban::clean($state) : null);
    }

    /** 05XXXXXXXX, 5XXXXXXXX or +9665XXXXXXXX, with any spaces or Arabic digits. */
    public static function isMobile(string $value): bool
    {
        return (bool) preg_match('/^\+9665\d{8}$/', OtpService::normalize($value));
    }

    /** +9665XXXXXXXX (as stored) back to 05XXXXXXXX for the form; anything else is shown as it is. */
    public static function localMobile(?string $phone): ?string
    {
        return $phone !== null && preg_match('/^\+9665\d{8}$/', $phone) ? '0'.substr($phone, 4) : $phone;
    }
}
