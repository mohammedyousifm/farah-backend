<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Api\ApiRequest;

class RequestOtpRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            // Saudi mobile (05XXXXXXXX / 5XXXXXXXX / +9665XXXXXXXX) or an email address.
            'identifier' => ['required', 'string', 'max:120', function ($attribute, $value, $fail) {
                $isEmail = filter_var($value, FILTER_VALIDATE_EMAIL);
                $isPhone = preg_match('/^(\+?966|0)?5\d{8}$/', preg_replace('/[\s-]/', '', (string) $value));
                if (! $isEmail && ! $isPhone) {
                    $fail(__('validation.custom.identifier.regex'));
                }
            }],
        ];
    }
}
