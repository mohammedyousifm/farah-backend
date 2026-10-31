<?php

namespace App\Http\Requests\Api\Provider;

use App\Http\Requests\Api\ApiRequest;
use App\Rules\GoogleMapsLink;
use App\Rules\SaudiIban;

class RegisterProviderRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'in:establishment'],
            'name_ar' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'bio_ar' => ['nullable', 'string', 'max:3000'],
            'bio_en' => ['nullable', 'string', 'max:3000'],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'website' => ['nullable', 'url', 'max:200'],
            'maps_url' => ['required', 'url', 'max:500', new GoogleMapsLink],
            'city_id' => ['required', 'exists:cities,id'],
            'address' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'cr_number' => ['required', 'digits:10'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'vat_number' => ['nullable', 'digits:15'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'iban' => ['nullable', new SaudiIban],
            'account_holder' => ['nullable', 'string', 'max:150'],
            'sector_ids' => ['required', 'array', 'min:1'],
            'sector_ids.*' => ['integer', 'exists:sectors,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['type' => 'establishment']);
        if ($this->iban) {
            $this->merge(['iban' => SaudiIban::clean((string) $this->iban)]);
        }
    }
}
