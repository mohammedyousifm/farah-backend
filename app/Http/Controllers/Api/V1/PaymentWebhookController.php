<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payment gateway → platform. Signature is checked against the raw body and the
 * gateway event id is stored uniquely, so retries are acknowledged but not re-applied.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentService $payments): JsonResponse
    {
        $processed = $payments->handleWebhook($request->getContent(), $request->header('X-Signature'));

        return response()->json(['received' => true, 'duplicate' => ! $processed]);
    }
}
