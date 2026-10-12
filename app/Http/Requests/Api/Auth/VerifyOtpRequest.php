<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Api\ApiRequest;

class VerifyOtpRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'min:4', 'max:8'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'locale' => ['nullable', 'in:ar,en'],
        ];
    }
}
