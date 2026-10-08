<?php

namespace App\Http\Requests\Api\Provider;

use App\Http\Requests\Api\ApiRequest;

/**
 * Create (POST) or update (PUT) an activity of a venue. `sector_id` is the activity
 * type (billiards, bowling, ...) and must be one the provider is approved for.
 * A booking is `min_slots`..`max_slots` consecutive slots of `slot_minutes` each.
 */
class ActivityRequest extends ApiRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';

        return [
            'sector_id' => [$req, 'integer', 'exists:sectors,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name_ar' => [$req, 'string', 'max:200'],
            'name_en' => ['nullable', 'string', 'max:200'],
            'description_ar' => ['nullable', 'string', 'max:1000'],
            'description_en' => ['nullable', 'string', 'max:1000'],
            'price_per_hour' => [$req, 'numeric', 'min:1', 'max:100000'],
            'slot_minutes' => ['sometimes', 'integer', 'between:15,480', 'multiple_of:5'],
            'min_slots' => ['sometimes', 'integer', 'between:1,48'],
            'max_slots' => ['sometimes', 'integer', 'between:1,48'],
            'capacity' => ['nullable', 'integer', 'between:1,1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            // Shortcut on create: add this many resources, named 1, 2, 3, ...
            'resources_count' => $creating ? ['sometimes', 'integer', 'between:1,100'] : ['prohibited'],
        ];
    }

    /** Columns that map 1:1 onto the activities table. */
    public function activityData(): array
    {
        return collect($this->validated())->except(['resources_count'])->all();
    }
}
