<?php

namespace App\Http\Requests\Api\Provider;

use App\Http\Requests\Api\ApiRequest;

/**
 * Weekly working hours, wall-clock in the business timezone. weekday 0 = Sunday.
 * closes_at at or before opens_at means the day runs past midnight (16:00 - 02:00).
 * Days left out keep their current hours.
 */
class VenueHoursRequest extends ApiRequest
{
    public function rules(): array
    {
        return self::hoursRules('required');
    }

    public static function hoursRules(string $presence): array
    {
        return [
            'hours' => [$presence, 'array', 'min:1', 'max:7'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
            'hours.*.is_closed' => ['sometimes', 'boolean'],
        ];
    }
}
