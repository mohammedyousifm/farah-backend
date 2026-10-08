<?php

namespace App\Http\Requests\Api\Customer;

use App\Http\Requests\Api\ApiRequest;

class RefundRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
            // Omit for a full refund; send the bookings to give up for a partial one.
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.booking_id' => ['required_with:items', 'integer', 'distinct'],
        ];
    }
}
