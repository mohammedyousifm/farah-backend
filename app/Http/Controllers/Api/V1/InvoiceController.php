<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "My tax invoices" in the customer app: one invoice per paid order. Every lookup is scoped to the user. */
class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function index(Request $request): JsonResponse
    {
        $page = $request->user()->orders()
            ->whereIn('status', Order::PAID_STATUSES)
            ->with(['provider', 'venue'])
            ->orderByDesc('confirmed_at')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), (int) config('farah.api.max_per_page', 50)));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Order $order) => $this->invoices->summary($this->invoices->issue($order)))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $model = $request->user()->orders()->with(InvoiceService::RELATIONS)->findOrFail($order);

        if (! $model->isPaid()) {
            throw new BusinessException(__('invoice.not_available'), 'invoice_not_available');
        }

        return response()->json(['data' => $this->invoices->details($this->invoices->issue($model))]);
    }
}
