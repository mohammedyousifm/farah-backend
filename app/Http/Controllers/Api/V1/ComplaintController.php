<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ComplaintStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\ComplaintReplyRequest;
use App\Http\Requests\Api\Customer\ComplaintRequest;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use App\Models\Venue;
use App\Services\ComplaintService;
use App\Support\DocNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Complaints, feedback and suggestions sent to the platform. */
class ComplaintController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ComplaintResource::collection($request->user()->complaints()->latest()->paginate(20));
    }

    public function store(ComplaintRequest $request): JsonResponse
    {
        $user = $request->user();
        $order = $request->order_id ? $user->orders()->findOrFail($request->order_id) : null;
        $venue = $order?->venue ?? ($request->venue_id ? Venue::find($request->venue_id) : null);

        $complaint = Complaint::create([
            'number' => DocNumber::make('CMP', 'complaints'),
            'user_id' => $user->id,
            'order_id' => $order?->id,
            'venue_id' => $venue?->id,
            'provider_id' => $order?->provider_id ?? $venue?->provider_id,
            'type' => $request->type,
            'subject' => $request->subject,
            'body' => $request->body,
            'status' => ComplaintStatus::Open,
        ]);

        return response()->json(['message' => __('api.complaints.created'), 'data' => new ComplaintResource($complaint)], 201);
    }

    public function show(Request $request, int $complaint): ComplaintResource
    {
        return new ComplaintResource($request->user()->complaints()->with('replies.user')->findOrFail($complaint));
    }

    public function reply(ComplaintReplyRequest $request, int $complaint, ComplaintService $complaints): ComplaintResource
    {
        $model = $request->user()->complaints()->findOrFail($complaint);

        // Closed tickets are read-only; a customer reply re-opens a resolved one.
        $complaints->reply($model, $request->user(), (string) $request->body);

        return new ComplaintResource($model->fresh()->load('replies.user'));
    }
}
