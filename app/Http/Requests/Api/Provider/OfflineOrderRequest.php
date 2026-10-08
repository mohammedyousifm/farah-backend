<?php

namespace App\Http\Requests\Api\Provider;

use App\Http\Requests\Api\Customer\BookingRequest;

/** Counter booking: the same booking lines as the customer app plus who paid and how. */
class OfflineOrderRequest extends BookingRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'payment_method' => ['required', 'in:cash,transfer,pos'],
            'customer.name' => ['required', 'string', 'max:120'],
            'customer.phone' => ['nullable', 'string', 'max:20'],
            'customer.email' => ['nullable', 'email', 'max:120'],
            'customer.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
