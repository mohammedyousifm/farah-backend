<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Api\ApiRequest;

class DeviceTokenRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:500'],
            'platform' => [$this->isMethod('delete') ? 'nullable' : 'required', 'in:ios,android,web'],
        ];
    }
}
