<?php

namespace App\Http\Requests\Api\Customer;

use App\Http\Requests\Api\ApiRequest;

class AvailabilityRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            // Calendar date in the business timezone; today when omitted.
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
