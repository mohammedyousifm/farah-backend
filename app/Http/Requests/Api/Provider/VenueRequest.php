<?php

namespace App\Http\Requests\Api\Provider;

use App\Http\Requests\Api\ApiRequest;

/**
 * Create (POST) or update (PUT/PATCH, or multipart POST to /venues/{id}) a venue.
 * Working hours may be sent along as `hours` (see VenueHoursRequest); a new venue
 * without them opens daily 10:00 - 00:00.
 */
class VenueRequest extends ApiRequest
{
    public function rules(): array
    {
        $req = $this->route('venue') ? 'sometimes' : 'required';

        return [
            'city_id' => [$req, 'integer', 'exists:cities,id'],
            'name_ar' => [$req, 'string', 'max:200'],
            'name_en' => ['nullable', 'string', 'max:200'],
            'summary_ar' => ['nullable', 'string', 'max:500'],
            'summary_en' => ['nullable', 'string', 'max:500'],
            'description_ar' => ['nullable', 'string', 'max:20000'],
            'description_en' => ['nullable', 'string', 'max:20000'],
            'address' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:20'],
            'audience' => ['sometimes', 'in:all,families,men,women,kids'],
            'min_age' => ['nullable', 'integer', 'between:0,99'],
            'refund_policy' => ['sometimes', 'in:none,flexible,moderate,strict'],
            'refund_policy_note' => ['nullable', 'string', 'max:1000'],
            'advance_booking_days' => ['sometimes', 'integer', 'between:1,365'],
            'cover' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ] + VenueHoursRequest::hoursRules('sometimes');
    }

    /** Columns that map 1:1 onto the venues table. */
    public function venueData(): array
    {
        return collect($this->validated())->except(['hours', 'cover'])->all();
    }

    /** Weekly hours when sent, null to leave them untouched. */
    public function hours(): ?array
    {
        return $this->has('hours') ? (array) $this->validated('hours') : null;
    }
}
