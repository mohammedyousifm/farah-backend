<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Requests\Api\Provider\LedgerIndexRequest;
use App\Http\Resources\LedgerEntryResource;
use App\Http\Resources\SettlementResource;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Provider wallet: balances, ledger statement and settlements (read-only). */
class FinanceController extends ProviderController
{
    public function balances(Request $request, LedgerService $ledger): JsonResponse
    {
        $provider = $this->provider($request);

        return response()->json(['data' => array_merge($ledger->balances($provider), [
            'currency' => config('farah.currency'),
            'bank' => [
                'bank_name' => $provider->bank_name,
                'iban' => $provider->iban,
                'account_holder' => $provider->account_holder,
            ],
        ])]);
    }

    public function ledger(LedgerIndexRequest $request): AnonymousResourceCollection
    {
        $entries = $this->provider($request)->ledgerEntries()
            ->where('account', 'provider')
            ->with('order:id,number')
            ->when($request->type, fn ($q, $t) => $q->whereIn('type', explode(',', $t)))
            ->when($request->integer('order_id'), fn ($q, $id) => $q->where('order_id', $id))
            ->tap(fn ($q) => $this->filterDays($q, $request->date_from, $request->date_to))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 30) ?: 30, 100));

        return LedgerEntryResource::collection($entries);
    }

    public function settlements(Request $request): AnonymousResourceCollection
    {
        return SettlementResource::collection(
            $this->provider($request)->settlements()
                ->when($request->status, fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
                ->latest('id')
                ->paginate(20)
        );
    }

    public function settlement(Request $request, int $settlement): SettlementResource
    {
        $model = $this->provider($request)->settlements()
            ->with(['entries' => fn ($q) => $q->where('account', 'provider')->with('order:id,number')->orderBy('id')])
            ->findOrFail($settlement);

        return new SettlementResource($model);
    }
}
