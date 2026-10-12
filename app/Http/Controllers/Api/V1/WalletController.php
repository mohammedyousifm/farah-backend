<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletTransactionResource;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer's Farah wallet: the balance and what went in and out. There is no top-up. */
class WalletController extends Controller
{
    public function show(Request $request, WalletService $wallet): JsonResponse
    {
        $transactions = $request->user()->walletTransactions()->with('order:id,number')->latest('id')->limit(100)->get();

        return response()->json(['data' => [
            'balance' => $wallet->balance($request->user()),
            'currency' => config('farah.currency'),
            'transactions' => WalletTransactionResource::collection($transactions),
        ]]);
    }
}
