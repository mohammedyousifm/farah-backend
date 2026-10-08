<?php

namespace App\Http\Requests\Api\Provider;

use App\Enums\DocumentType;
use App\Http\Requests\Api\ApiRequest;

class DocumentRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:'.implode(',', DocumentType::values())],
            'title' => ['nullable', 'string', 'max:150'],
            'number' => ['nullable', 'string', 'max:50'],
            'sector_id' => ['nullable', 'exists:sectors,id'],
            'issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            // Re-upload of a rejected/expired document.
            'replaces_id' => ['nullable', 'integer'],
        ];
    }
}
