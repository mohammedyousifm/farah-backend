<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Enums\RequestStatus;
use App\Exceptions\BusinessException;
use App\Http\Requests\Api\Provider\SectorRequestRequest;
use App\Http\Resources\SectorRequestResource;
use App\Http\Resources\SectorResource;
use App\Models\Sector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Sectors the provider works in (with approval status) and requests for new ones. */
class SectorController extends ProviderController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sectors = $this->provider($request)->sectors()
            ->with(['categories' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return SectorResource::collection($sectors);
    }

    public function requests(Request $request): AnonymousResourceCollection
    {
        return SectorRequestResource::collection($this->provider($request)->sectorRequests()->latest()->get());
    }

    /**
     * Either ask to join an existing sector (sector_id) or propose a new one (name_ar).
     */
    public function storeRequest(SectorRequestRequest $request): JsonResponse
    {
        $provider = $this->provider($request);

        if ($request->sector_id && $provider->sectors()->where('sectors.id', $request->sector_id)->exists()) {
            throw new BusinessException(__('api.providers.sector_exists'), 'sector_exists');
        }
        if ($provider->sectorRequests()->where('status', RequestStatus::Pending)
            ->where(fn ($q) => $request->sector_id
                ? $q->where('sector_id', $request->sector_id)
                : $q->where('name_ar', $request->name_ar))->exists()) {
            throw new BusinessException(__('api.providers.sector_request_pending'), 'sector_request_pending');
        }

        $sector = $request->sector_id ? Sector::find($request->sector_id) : null;

        $model = $provider->sectorRequests()->create([
            'sector_id' => $sector?->id,
            'name_ar' => $sector?->name_ar ?? $request->name_ar,
            'name_en' => $sector?->name_en ?? $request->name_en,
            'description' => $request->description,
            'status' => RequestStatus::Pending,
        ]);

        return response()->json(['message' => __('api.providers.sector_requested'), 'data' => new SectorRequestResource($model)], 201);
    }
}
