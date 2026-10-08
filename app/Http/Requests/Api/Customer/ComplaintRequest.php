<?php

namespace App\Http\Requests\Api\Customer;

use App\Http\Requests\Api\ApiRequest;
use App\Models\Complaint;

class ComplaintRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:'.implode(',', Complaint::TYPES)],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'order_id' => ['nullable', 'integer'],
            'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
        ];
    }
}
