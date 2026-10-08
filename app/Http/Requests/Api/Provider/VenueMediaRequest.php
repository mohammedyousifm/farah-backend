<?php

namespace App\Http\Requests\Api\Provider;

use App\Http\Requests\Api\ApiRequest;

/** Upload an image/GIF/video file, or attach an external video URL. */
class VenueMediaRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required_without:url', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime', 'max:51200'],
            'url' => ['required_without:file', 'nullable', 'url:https,http', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
