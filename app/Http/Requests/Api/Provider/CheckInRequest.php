<?php

namespace App\Http\Requests\Api\Provider;

use App\Http\Requests\Api\ApiRequest;

class CheckInRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30'],
            // Guards against scanning a code that belongs to another branch.
            'venue_id' => ['nullable', 'integer'],
        ];
    }
}
