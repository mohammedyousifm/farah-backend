<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Requests\Api\Provider\MediaReorderRequest;
use App\Http\Requests\Api\Provider\VenueMediaRequest;
use App\Http\Resources\VenueMediaResource;
use App\Support\Thumb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Venue gallery: uploaded images / GIFs / videos, or external video URLs. */
class VenueMediaController extends ProviderController
{
    public function index(Request $request, int $venue): AnonymousResourceCollection
    {
        return VenueMediaResource::collection($this->venue($request, $venue)->media()->get());
    }

    public function store(VenueMediaRequest $request, int $venue): JsonResponse
    {
        $model = $this->venue($request, $venue);
        $this->assertEditable($model);
        $sort = $request->filled('sort_order') ? $request->integer('sort_order') : ((int) $model->media()->max('sort_order') + 1);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $mime = (string) $file->getMimeType();
            $type = str_starts_with($mime, 'video/') ? 'video' : ($mime === 'image/gif' ? 'gif' : 'image');
            $media = $model->media()->create([
                'type' => $type,
                'path' => $file->store("venues/{$model->provider_id}/{$model->id}", 'public'),
                'sort_order' => $sort,
            ]);
        } else {
            $url = (string) $request->url;
            $media = $model->media()->create([
                'type' => preg_match('/\.(jpe?g|png|webp)(\?.*)?$/i', $url) ? 'image' : (preg_match('/\.gif(\?.*)?$/i', $url) ? 'gif' : 'video'),
                'url' => $url,
                'sort_order' => $sort,
            ]);
        }

        return response()->json(['message' => __('api.saved'), 'data' => new VenueMediaResource($media)], 201);
    }

    /** Body: {"ids": [5, 3, 9]} in the desired order. */
    public function reorder(MediaReorderRequest $request, int $venue): AnonymousResourceCollection
    {
        $model = $this->venue($request, $venue);
        $this->assertEditable($model);
        $own = $model->media()->pluck('id')->all();

        DB::transaction(function () use ($request, $model, $own) {
            foreach (array_values($request->input('ids')) as $i => $id) {
                if (in_array((int) $id, $own, true)) {
                    $model->media()->whereKey($id)->update(['sort_order' => $i]);
                }
            }
        });

        return VenueMediaResource::collection($model->media()->get());
    }

    public function destroy(Request $request, int $venue, int $media): JsonResponse
    {
        $item = $this->venue($request, $venue)->media()->findOrFail($media);

        if ($item->path) {
            Storage::disk('public')->delete($item->path);
            Thumb::forget($item->path);
        }
        $item->delete();

        return response()->json(['message' => __('api.deleted')]);
    }
}
