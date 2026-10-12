<?php

namespace App\Http\Resources;

use App\Models\Venue;
use Illuminate\Http\Request;

/**
 * Full venue details (customer app + provider app): the card plus hours, media and activities.
 *
 * @mixin Venue
 */
class VenueResource extends VenueCardResource
{
    /** Relations the details screen needs. */
    public const RELATIONS = ['city', 'provider', 'hours', 'media', 'activities.sector', 'activities.resources'];

    public function toArray(Request $request): array
    {
        $isProvider = $request->attributes->has('provider');

        return array_merge(parent::toArray($request), [
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'summary_ar' => $this->summary_ar,
            'summary_en' => $this->summary_en,
            'description' => $this->tr('description'),
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'city_id' => $this->city_id,
            'phone' => $this->phone,
            'audience' => $this->audience,
            'min_age' => $this->min_age,
            'advance_booking_days' => $this->advance_booking_days,
            'is_bookable' => $this->isBookable(),
            'refund_policy' => [
                'key' => $this->refund_policy,
                'label' => __('api.policies.'.$this->refund_policy.'.label'),
                'description' => __('api.policies.'.$this->refund_policy.'.description'),
                'note' => $this->refund_policy_note,
                'rules' => collect(config('farah.refund_policies.'.$this->refund_policy, []))
                    ->map(fn ($percent, $hours) => ['min_hours_before_start' => $hours, 'refund_percent' => $percent])->values(),
            ],
            'views_count' => $this->views_count,
            'media' => VenueMediaResource::collection($this->whenLoaded('media')),
            'hours' => $this->week(),
            'activities' => ActivityResource::collection($this->whenLoaded('activities',
                fn () => $isProvider ? $this->activities : $this->activities->where('is_active', true)->values())),
            // Provider-only moderation info.
            'rejection_reason' => $this->when($isProvider, $this->rejection_reason),
            'submitted_at' => $this->when($isProvider, $this->submitted_at?->toIso8601String()),
            'published_at' => $this->published_at?->toIso8601String(),
        ]);
    }

    /** Always seven rows, Sunday (0) to Saturday (6); a missing day is closed. */
    private function week(): array
    {
        return collect(range(0, 6))->map(function (int $weekday) {
            $hours = $this->hours->firstWhere('weekday', $weekday);
            $open = $hours && $hours->isOpen();

            return [
                'weekday' => $weekday,
                'day' => __('api.days.'.$weekday),
                'opens_at' => $open ? $hours->opens() : null,
                'closes_at' => $open ? $hours->closes() : null,
                'is_closed' => ! $open,
            ];
        })->all();
    }
}
