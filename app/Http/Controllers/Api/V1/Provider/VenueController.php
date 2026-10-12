<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Exceptions\BusinessException;
use App\Http\Requests\Api\Provider\VenueHoursRequest;
use App\Http\Requests\Api\Provider\VenueRequest;
use App\Http\Resources\VenueResource;
use App\Services\VenueService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

/** The provider's own venues (any status) and their weekly working hours. */
class VenueController extends ProviderController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $venues = $this->provider($request)->venues()
            ->with(VenueResource::RELATIONS)
            ->when($request->status, fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->q, fn ($q, $term) => $q->where(fn ($w) => $w->where('name_ar', 'like', Like::contains($term))->orWhere('name_en', 'like', Like::contains($term))))
            ->latest()
            ->paginate(min($request->integer('per_page', 20) ?: 20, 50));

        return VenueResource::collection($venues);
    }

    public function show(Request $request, int $venue): VenueResource
    {
        return new VenueResource($this->venue($request, $venue)->load(VenueResource::RELATIONS));
    }

    public function store(VenueRequest $request, VenueService $venues): JsonResponse
    {
        $provider = $this->provider($request);
        $data = $request->venueData();

        if ($request->hasFile('cover')) {
            $data['cover_image'] = $request->file('cover')->store("venues/{$provider->id}", 'public');
        }

        $venue = $venues->create($provider, $data, $request->hours());

        return response()->json([
            'message' => __('api.saved'),
            'data' => new VenueResource($venue->fresh()->load(VenueResource::RELATIONS)),
        ], 201);
    }

    /** Editing a published venue sends it back to review. Use PUT /hours to change only the working hours. */
    public function update(VenueRequest $request, int $venue, VenueService $venues): VenueResource
    {
        $model = $this->venue($request, $venue);
        $this->assertEditable($model);

        $data = $request->venueData();

        if ($request->hasFile('cover')) {
            $old = $model->cover_image;
            $data['cover_image'] = $request->file('cover')->store("venues/{$model->provider_id}", 'public');
            if ($old) {
                Storage::disk('public')->delete($old);
            }
        }

        $model = $venues->update($model, $data, $request->hours());

        return new VenueResource($model->load(VenueResource::RELATIONS));
    }

    public function destroy(Request $request, int $venue): JsonResponse
    {
        $model = $this->venue($request, $venue);

        if ($model->orders()->exists()) {
            throw new BusinessException(__('api.venues.cannot_delete'), 'venue_has_orders');
        }

        $model->delete();

        return response()->json(['message' => __('api.deleted')]);
    }

    public function submit(Request $request, int $venue, VenueService $venues): JsonResponse
    {
        $model = $venues->submit($this->venue($request, $venue));

        return response()->json([
            'message' => __('api.venues.submitted'),
            'data' => new VenueResource($model->fresh()->load(VenueResource::RELATIONS)),
        ]);
    }

    public function hours(Request $request, int $venue): JsonResponse
    {
        return $this->hoursResponse($request, $this->venue($request, $venue));
    }

    /** Replace the hours of the days sent. Takes effect at once; existing bookings are not touched. */
    public function updateHours(VenueHoursRequest $request, int $venue, VenueService $venues): JsonResponse
    {
        $model = $this->venue($request, $venue);
        $this->assertEditable($model);

        $venues->syncHours($model, $request->validated('hours'));

        return $this->hoursResponse($request, $model, __('api.saved'));
    }

    private function hoursResponse(Request $request, $venue, ?string $message = null): JsonResponse
    {
        $hours = (new VenueResource($venue->load('hours')))->resolve($request)['hours'];

        return response()->json(array_filter(['message' => $message, 'data' => $hours], fn ($v) => $v !== null));
    }
}
