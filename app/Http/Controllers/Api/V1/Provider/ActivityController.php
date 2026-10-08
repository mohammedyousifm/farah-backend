<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Enums\BookingStatus;
use App\Exceptions\BusinessException;
use App\Http\Requests\Api\Provider\ActivityRequest;
use App\Http\Requests\Api\Provider\ResourceRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\ResourceResource;
use App\Models\Activity;
use App\Models\Category;
use App\Services\VenueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What can be played in a venue and on what: activities (billiards, 100 SAR / hour)
 * and their resources (Table 1, Table 2, ...). Price and slot changes apply to new
 * bookings only; existing bookings keep the price they were made at.
 */
class ActivityController extends ProviderController
{
    private const RELATIONS = ['sector', 'resources'];

    public function index(Request $request, int $venue): AnonymousResourceCollection
    {
        return ActivityResource::collection($this->venue($request, $venue)->activities()->with(self::RELATIONS)->get());
    }

    public function show(Request $request, int $venue, int $activity): ActivityResource
    {
        return new ActivityResource($this->activity($request, $venue, $activity)->load(self::RELATIONS));
    }

    public function store(ActivityRequest $request, int $venue, VenueService $venues): JsonResponse
    {
        $model = $this->venue($request, $venue);
        $this->assertEditable($model);

        $data = $request->activityData();
        $venues->assertSectorAllowed($this->provider($request), (int) $data['sector_id']);
        $this->assertConsistent($data);

        $activity = DB::transaction(function () use ($model, $data, $request) {
            $activity = $model->activities()->create($data + ['sort_order' => (int) $model->activities()->max('sort_order') + 1]);

            for ($i = 1; $i <= $request->integer('resources_count'); $i++) {
                $activity->resources()->create(['name_ar' => (string) $i, 'name_en' => (string) $i, 'sort_order' => $i]);
            }

            return $activity;
        });

        return response()->json(['message' => __('api.saved'), 'data' => new ActivityResource($activity->fresh()->load(self::RELATIONS))], 201);
    }

    public function update(ActivityRequest $request, int $venue, int $activity, VenueService $venues): ActivityResource
    {
        $model = $this->activity($request, $venue, $activity);
        $this->assertEditable($model->venue);

        $data = $request->activityData();
        if (isset($data['sector_id']) && (int) $data['sector_id'] !== $model->sector_id) {
            $venues->assertSectorAllowed($this->provider($request), (int) $data['sector_id']);
        }
        $this->assertConsistent($data, $model);

        // Held slots are keyed by their start time: a new slot length would no longer line up with them.
        if (isset($data['slot_minutes']) && (int) $data['slot_minutes'] !== $model->slot_minutes
            && $model->bookings()->whereIn('status', BookingStatus::HOLDING)->where('ends_at', '>', now())->exists()) {
            throw new BusinessException(__('api.activities.slot_locked'), 'activity_has_bookings');
        }

        $model->update($data);

        return new ActivityResource($model->fresh()->load(self::RELATIONS));
    }

    public function destroy(Request $request, int $venue, int $activity): JsonResponse
    {
        $model = $this->activity($request, $venue, $activity);

        if ($model->bookings()->exists()) {
            throw new BusinessException(__('api.activities.has_bookings'), 'activity_has_bookings');
        }
        $model->delete();

        return response()->json(['message' => __('api.deleted')]);
    }

    // ------------------------------------------------------------ Resources

    public function resources(Request $request, int $venue, int $activity): AnonymousResourceCollection
    {
        return ResourceResource::collection($this->activity($request, $venue, $activity)->resources()->get());
    }

    public function storeResource(ResourceRequest $request, int $venue, int $activity): JsonResponse
    {
        $model = $this->activity($request, $venue, $activity);
        $this->assertEditable($model->venue);

        $resource = $model->resources()->create($request->validated() + ['sort_order' => (int) $model->resources()->max('sort_order') + 1]);

        return response()->json(['message' => __('api.saved'), 'data' => new ResourceResource($resource->fresh())], 201);
    }

    /** Deactivating a resource stops new bookings on it; bookings already made stay valid. */
    public function updateResource(ResourceRequest $request, int $venue, int $activity, int $resource): ResourceResource
    {
        $model = $this->activity($request, $venue, $activity);
        $this->assertEditable($model->venue);

        $item = $model->resources()->findOrFail($resource);
        $item->update($request->validated());

        return new ResourceResource($item->fresh());
    }

    public function destroyResource(Request $request, int $venue, int $activity, int $resource): JsonResponse
    {
        $item = $this->activity($request, $venue, $activity)->resources()->findOrFail($resource);

        if ($item->bookings()->exists()) {
            throw new BusinessException(__('api.resources.has_bookings'), 'resource_has_bookings');
        }
        $item->delete();

        return response()->json(['message' => __('api.deleted')]);
    }

    /** Checks that span the request and what is already stored: slot range and category / sector match. */
    private function assertConsistent(array $data, ?Activity $current = null): void
    {
        $min = (int) ($data['min_slots'] ?? $current?->min_slots ?? 1);
        $max = (int) ($data['max_slots'] ?? $current?->max_slots ?? 4);

        if ($max < $min) {
            throw ValidationException::withMessages(['max_slots' => __('validation.gte.numeric', [
                'attribute' => __('validation.attributes.max_slots'),
                'value' => $min,
            ])]);
        }

        $categoryId = array_key_exists('category_id', $data) ? $data['category_id'] : $current?->category_id;
        $sectorId = (int) ($data['sector_id'] ?? $current?->sector_id);

        if ($categoryId && ! Category::whereKey($categoryId)->where('sector_id', $sectorId)->exists()) {
            throw ValidationException::withMessages(['category_id' => __('validation.exists', [
                'attribute' => __('validation.attributes.category_id'),
            ])]);
        }
    }
}
