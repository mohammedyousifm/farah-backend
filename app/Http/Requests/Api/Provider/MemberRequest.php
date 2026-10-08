<?php

namespace App\Http\Requests\Api\Provider;

use App\Http\Requests\Api\ApiRequest;
use App\Models\ProviderMember;

class MemberRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'phone' => [$this->isMethod('post') ? 'required' : 'prohibited', 'string', 'regex:/^(\+?966|0)?5\d{8}$/'],
            'name' => ['nullable', 'string', 'max:120'],
            'role' => [$this->isMethod('post') ? 'required' : 'sometimes', 'in:manager,staff'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['in:'.implode(',', ProviderMember::PERMISSIONS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
