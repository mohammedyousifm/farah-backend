<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\TrackRequest;
use App\Models\BehaviorEvent;
use Illuminate\Http\JsonResponse;

/**
 * Behavioural events for recommendations (FR-AI-02). Stored only with consent:
 * signed-in users via their tracking_consent flag, guests via `consent: true`.
 */
class TrackingController extends Controller
{
    public function store(TrackRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $consented = $user ? (bool) $user->tracking_consent : $request->boolean('consent');

        if (! $consented || (! $user && ! $request->device_id)) {
            return response()->json(['data' => ['stored' => 0]], 202);
        }

        $rows = collect($request->input('events'))->map(fn ($e) => [
            'user_id' => $user?->id,
            'device_id' => $request->device_id,
            'name' => $e['name'],
            'subject_type' => $e['subject_type'] ?? null,
            'subject_id' => isset($e['subject_type']) ? ($e['subject_id'] ?? null) : null,
            'properties' => isset($e['properties']) ? json_encode($e['properties'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => isset($e['occurred_at']) ? now()->parse($e['occurred_at'])->min(now()) : now(),
        ]);

        BehaviorEvent::insert($rows->all());

        return response()->json(['data' => ['stored' => $rows->count()]], 202);
    }
}
