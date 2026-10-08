<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\Payment;
use App\Services\Integrations\Drivers\FakePaymentGateway;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Sandbox checkout page for the "fake" payment driver. The mobile app opens
 * payment.checkout_url in a web view; the buttons here fire a correctly signed
 * webhook through PaymentService, exactly like a real gateway would.
 */
class FakePaymentController extends Controller
{
    public function show(string $reference): View
    {
        $payment = $this->payment($reference);

        return view('payments.fake', ['payment' => $payment->load('orders.venue', 'orders.bookings')]);
    }

    public function complete(Request $request, string $reference, PaymentService $payments): View
    {
        $request->validate(['action' => ['required', 'in:pay,fail']]);
        $payment = $this->payment($reference);
        $error = null;

        if ($payment->status === PaymentStatus::Initiated) {
            $paid = $request->input('action') === 'pay';
            $body = json_encode([
                'id' => 'evt_'.Str::lower(Str::random(20)),
                'type' => $paid ? 'payment.paid' : 'payment.failed',
                'data' => [
                    'reference' => $payment->reference,
                    'id' => $payment->gateway_payment_id,
                    'method' => 'mada',
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'message' => $paid ? null : 'Declined by cardholder (sandbox)',
                ],
            ], JSON_UNESCAPED_UNICODE);

            try {
                $payments->handleWebhook($body, FakePaymentGateway::sign($body));
            } catch (BusinessException $e) {
                $error = $e->getMessage();
            }
        }

        return view('payments.result', ['payment' => $payment->fresh('orders'), 'error' => $error]);
    }

    private function payment(string $reference): Payment
    {
        // The sandbox page can "pay" any order for free: never reachable in production.
        abort_unless(config('farah.integrations.payment.driver') === 'fake' && ! app()->isProduction(), 404);
        app()->setLocale('ar'); // the page is Arabic

        return Payment::where('reference', $reference)->firstOrFail();
    }
}
