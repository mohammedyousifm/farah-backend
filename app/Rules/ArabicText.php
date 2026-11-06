<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An Arabic name field must hold Arabic letters. Latin words, digits and symbols may sit next to them
 * ("صالة PS5"), but a name with no Arabic letter at all ("Xena Cochran") is refused: it would show as the
 * Arabic name in the dashboards and the app.
 */
class ArabicText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) !== '' && ! preg_match('/\p{Arabic}/u', $value)) {
            $fail(__('validation.arabic_text'));
        }
    }
}
