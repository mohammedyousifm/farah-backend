<?php

namespace App\Http\Resources;

use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Provider profile. Public callers get the storefront fields only; the provider's
 * own members (request attribute "provider") also get legal, bank and status data.
 *
 * @mixin Provider
 */
class ProviderProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $own = $request->attributes->get('provider')?->id === $this->id;

        return [
            'id' => $this->id,
            'type' => $this->type?->toArray(),
            'name' => $this->displayName(),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'bio' => app()->getLocale() === 'en' && $this->bio_en ? $this->bio_en : $this->bio_ar,
            'bio_ar' => $this->bio_ar,
            'bio_en' => $this->bio_en,
            'logo_url' => $this->logoUrl(),
            'cover_url' => $this->coverUrl(),
            'website' => $this->website,
            'maps_url' => $this->maps_url,
            'city' => new CityResource($this->whenLoaded('city')),
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
            'sectors' => SectorResource::collection($this->whenLoaded('sectors')),
            'venues' => VenueCardResource::collection($this->whenLoaded('venues')),

            $this->mergeWhen($own, fn () => [
                'email' => $this->email,
                'phone' => $this->phone,
                'city_id' => $this->city_id,
                'address' => $this->address,
                'lat' => $this->lat,
                'lng' => $this->lng,
                'cr_number' => $this->cr_number,
                'legal_name' => $this->legal_name,
                'vat_number' => $this->vat_number,
                'status' => $this->status?->toArray(),
                'rejection_reason' => $this->rejection_reason,
                'submitted_at' => $this->submitted_at?->toIso8601String(),
                'approved_at' => $this->approved_at?->toIso8601String(),
            ]),

            // Identity and bank details: owner / managers / members with profile or finance access only.
            $this->mergeWhen($own && $this->canSeeSensitive($request), fn () => [
                'bank_name' => $this->bank_name,
                'iban' => $this->iban,
                'account_holder' => $this->account_holder,
            ]),
        ];
    }

    private function canSeeSensitive(Request $request): bool
    {
        $member = $request->attributes->get('provider_member');

        // No membership attribute = the owner registering right now.
        return ! $member || $member->allows('profile.manage') || $member->allows('finance.view');
    }
}
