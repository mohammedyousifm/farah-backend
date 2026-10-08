<?php

namespace App\Http\Requests\Api\Provider;

use App\Http\Requests\Api\ApiRequest;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;

/** Take a run of slots of one resource out of sale (maintenance, private use). */
class SlotBlockRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'resource_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'slots' => ['required', 'integer', 'min:1', 'max:96'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** A start time sent without an offset is wall-clock time in the business timezone. */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->input('starts_at'), AvailabilityService::timezone())->utc();
    }
}
