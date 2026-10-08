<?php

namespace App\Http\Requests\Api\Customer;

use App\Http\Requests\Api\ApiRequest;

class VenueIndexRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'sector_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'city_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'audience' => ['nullable', 'in:all,families,men,women,kids'],
            'featured' => ['nullable', 'boolean'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'sort' => ['nullable', 'in:recommended,price,rating,nearest,latest'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
