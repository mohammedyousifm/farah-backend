<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\Payment;
use App\Services\Integrations\Drivers\FakePaymentGateway;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sandbox checkout page for the "fake" payment driver. The mobile app opens
 * payment.checkout_url in a web view; the buttons here fire a correctly signed
 * webhook through PaymentService, exactly like a real gateway would.
 *
 * Access rules (it acts like a hosted gateway page, so there is no app session here):
 * - only with the "fake" driver and never in production;
 * - only through the signed, short-lived link the API returned (a bare reference gives 403);
 * - "pay" is refused once the order's payment window ended (the hold is released instead);
 * - responses are never cached (no-store).
 */
class FakePaymentController extends Controller
{
    public function show(Request $request, string $reference, OrderService $orders): Response
    {
        $payment = $this->payment($request, $reference);

        if ($payment->status === PaymentStatus::Initiated && ($error = $this->windowError($payment, $orders))) {
            return $this->noStore(view('payments.result', ['payment' => $payment->fresh('orders'), 'error' => $error]));
        }

        return $this->noStore(view('payments.fake', [
            'payment' => $payment->load('orders.venue', 'orders.bookings'),
            'action' => URL::temporarySignedRoute('payments.fake.complete', FakePaymentGateway::linkExpiry(), ['reference' => $payment->reference]),
        ]));
    }

    public function complete(Request $request, string $reference, PaymentService $payments, OrderService $orders): Response
    {
        $request->validate(['action' => ['required', 'in:pay,fail']]);
        $payment = $this->payment($request, $reference);
        $error = null;

        if ($payment->status === PaymentStatus::Initiated) {
            $paid = $request->input('action') === 'pay';
            $error = $paid ? $this->windowError($payment, $orders) : null;
        }

        if ($payment->status === PaymentStatus::Initiated && $error === null) {
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

        return $this->noStore(view('payments.result', ['payment' => $payment->fresh('orders'), 'error' => $error]));
    }

    private function payment(Request $request, string $reference): Payment
    {
        // The sandbox page can "pay" any order for free: never reachable in production.
        abort_unless(config('farah.integrations.payment.driver') === 'fake' && ! app()->isProduction(), 404);
        abort_unless($request->hasValidSignature(), 403);
        app()->setLocale('ar'); // the page is Arabic

        return Payment::where('reference', $reference)->firstOrFail();
    }

    /** Null while every order of the payment is still inside its payment window, else the reason. */
    private function windowError(Payment $payment, OrderService $orders): ?string
    {
        try {
            foreach ($payment->orders as $order) {
                $orders->assertPayable($order);
            }
        } catch (BusinessException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function noStore(mixed $view): Response
    {
        return response($view)->header('Cache-Control', 'no-store, private');
    }
}
