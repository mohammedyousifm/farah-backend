<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Api\ApiRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends ApiRequest
{
    public function rules(): array
    {
        $id = $this->user()->id;

        return [
            // First name and family name: at least two words of two characters or more.
            'name' => ['sometimes', 'required', 'string', 'max:120', 'regex:/^\s*\S{2,}(\s+\S{2,})+\s*$/u'],
            'email' => ['sometimes', 'nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($id)],
            'city_id' => ['sometimes', 'nullable', 'exists:cities,id'],
            // GPS point from the phone; both or neither (null clears it).
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'in:male,female'],
            'locale' => ['sometimes', 'in:ar,en'],
            'marketing_consent' => ['sometimes', 'boolean'],
            'tracking_consent' => ['sometimes', 'boolean'],
            'avatar' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
