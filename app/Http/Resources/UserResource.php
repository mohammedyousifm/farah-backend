<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'type' => $this->type?->toArray(),
            'locale' => $this->locale,
            'avatar_url' => $this->avatar ? Storage::disk('public')->url($this->avatar) : null,
            'city_id' => $this->city_id,
            'city' => new CityResource($this->whenLoaded('city')),
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'marketing_consent' => (bool) $this->marketing_consent,
            'tracking_consent' => (bool) $this->tracking_consent,
            'phone_verified' => (bool) $this->phone_verified_at,
            'email_verified' => (bool) $this->email_verified_at,
            'providers' => $this->whenLoaded('providers', fn () => $this->providers
                ->filter(fn ($p) => $p->pivot->is_active)
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->displayName(),
                    'status' => $p->status?->toArray(),
                    'role' => $p->pivot->role,
                    'permissions' => $p->pivot->role === 'staff'
                        ? (is_string($p->pivot->permissions) ? json_decode($p->pivot->permissions, true) : ($p->pivot->permissions ?? []))
                        : ['*'],
                ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
