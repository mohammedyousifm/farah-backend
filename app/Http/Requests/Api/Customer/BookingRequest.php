<?php

namespace App\Http\Requests\Api\Customer;

use App\Http\Requests\Api\ApiRequest;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;

/**
 * Book one resource for a run of slots ({activity_id, resource_id?, starts_at, slots}),
 * or several at once in the same venue ({lines: [...]}).
 * Omit resource_id to get the first free table / lane / room.
 */
class BookingRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'lines' => ['sometimes', 'array', 'min:1', 'max:20'],
            'lines.*.activity_id' => ['required', 'integer'],
            'lines.*.resource_id' => ['nullable', 'integer'],
            'lines.*.starts_at' => ['required', 'date'],
            'lines.*.slots' => ['required', 'integer', 'min:1', 'max:48'],
            'activity_id' => ['required_without:lines', 'prohibits:lines', 'integer'],
            'resource_id' => ['nullable', 'integer'],
            'starts_at' => ['required_without:lines', 'date'],
            'slots' => ['required_without:lines', 'integer', 'min:1', 'max:48'],
        ];
    }

    /**
     * Booking lines in the shape OrderService expects.
     * A start time sent without an offset is read as wall-clock time in the business timezone.
     *
     * @return array<int, array{activity_id: int, resource_id: ?int, starts_at: string, slots: int}>
     */
    public function lines(): array
    {
        $lines = $this->has('lines') ? (array) $this->input('lines') : [$this->only(['activity_id', 'resource_id', 'starts_at', 'slots'])];

        return array_values(array_map(fn (array $line) => [
            'activity_id' => (int) $line['activity_id'],
            'resource_id' => empty($line['resource_id']) ? null : (int) $line['resource_id'],
            'starts_at' => CarbonImmutable::parse($line['starts_at'], AvailabilityService::timezone())->utc()->toIso8601String(),
            'slots' => (int) $line['slots'],
        ], $lines));
    }
}
