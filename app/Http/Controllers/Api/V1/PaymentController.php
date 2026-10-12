<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer's payments: status polling after the web view closes, and paying again. */
class PaymentController extends Controller
{
    public function show(Request $request, string $reference): PaymentResource
    {
        $payment = Payment::where('reference', $reference)
            ->where('user_id', $request->user()->id)
            ->with('orders')
            ->firstOrFail();

        return new PaymentResource($payment);
    }

    /** Retry payment for a still-pending order (new checkout session). The slots stay held until the order expires. */
    public function retry(Request $request, int $order, PaymentService $payments): JsonResponse
    {
        $model = $request->user()->orders()->findOrFail($order);
        $payment = $payments->checkout($request->user(), collect([$model]));

        return response()->json(['data' => ['payment' => new PaymentResource($payment)]], 201);
    }

    /** Pay a pending order from the Farah wallet. The balance must cover the whole order. */
    public function wallet(Request $request, int $order, WalletService $wallet, PaymentService $payments): JsonResponse
    {
        $model = $request->user()->orders()->findOrFail($order);
        $payment = $wallet->pay($request->user(), $model, $payments);

        return response()->json(['data' => [
            'payment' => new PaymentResource($payment),
            'wallet_balance' => $wallet->balance($request->user()),
        ]]);
    }
}
