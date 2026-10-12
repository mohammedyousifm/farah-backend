<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The invoice PDF. Reached only through the signed, short-lived link the API hands the invoice's
 * owner: the phone's browser or PDF viewer opens it, and those do not carry the app's sign-in token.
 */
class InvoicePdfController extends Controller
{
    public function __invoke(Request $request, int $order, InvoiceService $invoices): Response
    {
        $model = Order::with(InvoiceService::RELATIONS)->findOrFail($order);
        abort_unless($model->isPaid(), 404);

        app()->setLocale($request->query('lang') === 'en' ? 'en' : 'ar');
        $invoices->issue($model);

        return response($invoices->pdf($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$model->invoice_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
