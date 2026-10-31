<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Saudi IBAN: SA, two check digits and 20 more digits (24 characters). Checked in its cleaned form, so a copied
 * "sa03 8000 0000 6080 1016 7519" or Arabic digits pass, and with the IBAN check digits (mod 97), so a mistyped
 * digit is caught here and not at the first transfer.
 */
class SaudiIban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $iban = self::clean((string) $value);

        if (! preg_match('/^SA\d{22}$/', $iban)) {
            $fail(__('validation.custom.iban.format', ['length' => strlen($iban)]));
        } elseif (! self::checksumOk($iban)) {
            $fail(__('validation.custom.iban.checksum'));
        }
    }

    /** Upper case, Latin digits, no spaces or dashes. */
    public static function clean(string $value): string
    {
        $value = strtr($value, array_combine(
            [...mb_str_split('٠١٢٣٤٥٦٧٨٩'), ...mb_str_split('۰۱۲۳۴۵۶۷۸۹')],
            array_map('strval', [...range(0, 9), ...range(0, 9)]),
        ));

        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    /** ISO 13616: move the first four characters to the end, letters to numbers (A=10), the result mod 97 is 1. */
    public static function checksumOk(string $iban): bool
    {
        $digits = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }

        return $remainder === 1;
    }
}
