<?php

namespace App\Http\Requests\Api\Provider;

use App\Http\Requests\Api\ApiRequest;

/** Storefront + payout details. Legal identity (type, national_id, cr_number) is fixed after registration. */
class UpdateProviderProfileRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'required', 'string', 'max:150'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:150'],
            'bio_ar' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'bio_en' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'website' => ['sometimes', 'nullable', 'url', 'max:200'],
            'city_id' => ['sometimes', 'exists:cities,id'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'vat_number' => ['sometimes', 'nullable', 'digits:15'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'iban' => ['sometimes', 'nullable', 'regex:/^SA\d{22}$/'],
            'account_holder' => ['sometimes', 'nullable', 'string', 'max:150'],
            'logo' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'cover' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->iban) {
            $this->merge(['iban' => strtoupper(str_replace(' ', '', $this->iban))]);
        }
    }
}
