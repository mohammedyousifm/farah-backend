<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Http\Requests\Api\Provider\DocumentRequest;
use App\Http\Resources\ProviderDocumentResource;
use App\Models\ProviderDocument;
use App\Services\ProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Verification documents (national ID, CR, licences, ...). Reviewed by admins. */
class DocumentController extends ProviderController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ProviderDocumentResource::collection($this->provider($request)->documents()->latest()->get());
    }

    /** Upload a new document, or re-upload (replaces_id) a rejected / expired / pending one. */
    public function store(DocumentRequest $request): JsonResponse
    {
        $provider = $this->provider($request);
        $old = $request->replaces_id ? $provider->documents()->findOrFail($request->replaces_id) : null;

        if ($old && $old->status === DocumentStatus::Approved && ! ($old->expires_at && $old->isExpiringSoon())) {
            throw new BusinessException(__('api.providers.document_locked'), 'document_locked');
        }

        $path = $request->file('file')->store("providers/{$provider->id}/documents", ProviderDocument::DISK);

        $document = DB::transaction(function () use ($provider, $request, $path, $old) {
            if ($old) {
                if ($old->file_path) {
                    Storage::disk(ProviderDocument::DISK)->delete($old->file_path);
                }
                $old->delete();
            }

            return $provider->documents()->create([
                'type' => $request->type,
                'title' => $request->title,
                'number' => $request->number,
                'sector_id' => $request->sector_id,
                'issued_at' => $request->issued_at,
                'expires_at' => $request->expires_at,
                'file_path' => $path,
                'status' => DocumentStatus::Pending,
            ]);
        });
        app(ProviderService::class)->documentUploaded($document);

        return response()->json(['message' => __('api.providers.document_uploaded'), 'data' => new ProviderDocumentResource($document)], 201);
    }
}
