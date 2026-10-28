<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Requests\Api\Provider\RegisterProviderRequest;
use App\Http\Requests\Api\Provider\UpdateProviderProfileRequest;
use App\Http\Resources\ProviderProfileResource;
use App\Services\ProviderService;
use App\Support\Thumb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends ProviderController
{
    /** Any signed-in user can apply to become a provider (reviewed by admins). */
    public function register(RegisterProviderRequest $request, ProviderService $providers): JsonResponse
    {
        $provider = $providers->register($request->user(), $request->validated());
        // Resources read the container's request (the FormRequest is a copy): expose owner-only fields.
        request()->attributes->set('provider', $provider);

        return response()->json([
            'message' => __('api.providers.registered'),
            'data' => new ProviderProfileResource($provider->load('city', 'sectors')),
        ], 201);
    }

    public function show(Request $request): ProviderProfileResource
    {
        return new ProviderProfileResource($this->provider($request)->load('city', 'sectors'));
    }

    public function update(UpdateProviderProfileRequest $request): ProviderProfileResource
    {
        $provider = $this->provider($request);
        $data = $request->safe()->except(['logo', 'cover']);

        foreach (['logo', 'cover'] as $image) {
            if ($request->hasFile($image)) {
                $data[$image] = $request->file($image)->store("providers/{$provider->id}", 'public');
                if ($provider->{$image}) {
                    Storage::disk('public')->delete($provider->{$image});
                    Thumb::forget($provider->{$image});
                }
            }
        }

        $provider->update($data);

        return new ProviderProfileResource($provider->fresh()->load('city', 'sectors'));
    }
}
