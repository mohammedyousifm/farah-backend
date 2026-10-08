<?php

namespace App\Http\Requests\Api\Provider;

use App\Http\Requests\Api\ApiRequest;

/** Ask the platform to add a brand-new sector, or to join an existing one (sector_id). */
class SectorRequestRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'sector_id' => ['nullable', 'exists:sectors,id'],
            'name_ar' => ['required_without:sector_id', 'nullable', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
