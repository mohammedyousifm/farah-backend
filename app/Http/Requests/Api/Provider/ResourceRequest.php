<?php

namespace App\Http\Requests\Api\Provider;

use App\Http\Requests\Api\ApiRequest;

/** A bookable unit of an activity: "Table 3", "Lane 1", "VIP room". */
class ResourceRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name_ar' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
