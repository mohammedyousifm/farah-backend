<?php

namespace App\Http\Requests\Api\Customer;

use App\Http\Requests\Api\ApiRequest;
use App\Models\BehaviorEvent;

class TrackRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'device_id' => ['nullable', 'string', 'max:100'],
            // Guests must state consent explicitly; logged-in users use their saved consent.
            'consent' => ['nullable', 'boolean'],
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.name' => ['required', 'in:'.implode(',', BehaviorEvent::NAMES)],
            'events.*.subject_type' => ['nullable', 'in:venue,category,sector,provider'],
            'events.*.subject_id' => ['nullable', 'integer'],
            'events.*.properties' => ['nullable', 'array'],
            'events.*.occurred_at' => ['nullable', 'date'],
        ];
    }
}
