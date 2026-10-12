<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Services\InvoiceService;
use App\Services\OrderService;
use Laravel\Sanctum\Sanctum;

/** "My tax invoices": one numbered invoice per paid order, with the tax QR code and a PDF. */
class InvoiceTest extends ApiTestCase
{
    private function paidOrder($customer, array $provider = ['vat_number' => '310122393500003', 'legal_name' => 'شركة العليا للترفيه']): Order
    {
        $venue = $this->venue(tap($this->provider(), fn ($p) => $p->forceFill($provider)->save()));
        $order = app(OrderService::class)->createOnline($customer, [$this->line($venue, slots: 2, startsAt: $this->slot(days: 3 + Order::count()))]);

        return app(OrderService::class)->confirm($order)->fresh();
    }

    public function test_paying_an_order_numbers_its_invoice_in_sequence(): void
    {
        $customer = $this->customer();
        $first = $this->paidOrder($customer);
        $second = $this->paidOrder($customer);
        $year = now()->year;

        $this->assertSame("INV-{$year}-000001", $first->invoice_number);
        $this->assertSame("INV-{$year}-000002", $second->invoice_number);
        $this->assertNotNull($first->invoice_issued_at);

        // Asking again never renumbers.
        $this->assertSame($first->invoice_number, app(InvoiceService::class)->issue($first)->invoice_number);
    }

    public function test_customer_lists_and_opens_only_their_own_invoices(): void
    {
        $customer = $this->customer();
        $order = $this->paidOrder($customer);
        $someoneElse = $this->paidOrder($this->customer('+966502222222'));

        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/invoices')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $order->invoice_number)
            ->assertJsonPath('data.0.is_tax_invoice', true)
            ->assertJsonPath('meta.total', 1);

        $invoice = $this->getJson("/api/v1/invoices/{$order->id}")->assertOk()
            ->assertJsonPath('data.seller.vat_number', '310122393500003')
            ->assertJsonPath('data.seller.name', 'شركة العليا للترفيه')
            ->assertJsonPath('data.lines.0.quantity', 2)
            ->json('data');

        // Net + VAT is exactly what the customer paid.
        $this->assertEqualsWithDelta($invoice['total'], $invoice['net'] + $invoice['vat_amount'], 0.001);
        $this->assertSame(15, $invoice['vat_rate']);

        $this->getJson("/api/v1/invoices/{$someoneElse->id}")->assertNotFound();
    }

    public function test_qr_code_carries_the_five_fields_the_tax_authority_asks_for(): void
    {
        $order = $this->paidOrder($this->customer());
        $raw = base64_decode(app(InvoiceService::class)->qrPayload($order->load('provider')));

        $fields = [];
        for ($i = 0; $i < strlen($raw);) {
            $length = ord($raw[$i + 1]);
            $fields[ord($raw[$i])] = substr($raw, $i + 2, $length);
            $i += 2 + $length;
        }

        $this->assertSame([1, 2, 3, 4, 5], array_keys($fields));
        $this->assertSame('شركة العليا للترفيه', $fields[1]);
        $this->assertSame('310122393500003', $fields[2]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $fields[3]);
        $this->assertSame(number_format((float) $order->total, 2, '.', ''), $fields[4]);
        $this->assertSame(number_format((float) $order->vat_amount, 2, '.', ''), $fields[5]);
    }

    public function test_seller_without_a_vat_number_gets_a_plain_invoice(): void
    {
        $customer = $this->customer();
        $order = $this->paidOrder($customer, ['vat_number' => null]);
        Sanctum::actingAs($customer);

        $invoice = $this->getJson("/api/v1/invoices/{$order->id}")->assertOk()
            ->assertJsonPath('data.is_tax_invoice', false)
            ->assertJsonPath('data.qr', null)
            ->assertJsonPath('data.seller.vat_number', null)
            ->json('data');

        $this->assertEquals(0, $invoice['vat_amount']);
        $this->assertEquals($order->total, $invoice['net']);
    }

    public function test_unpaid_order_has_no_invoice(): void
    {
        $customer = $this->customer();
        $order = app(OrderService::class)->createOnline($customer, [$this->line($this->venue($this->provider()))]);
        Sanctum::actingAs($customer);

        $this->getJson("/api/v1/invoices/{$order->id}")->assertStatus(422)->assertJsonPath('code', 'invoice_not_available');
        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(0, 'data');
        $this->assertNull($order->fresh()->invoice_number);
    }

    public function test_pdf_opens_only_through_its_signed_link(): void
    {
        $customer = $this->customer();
        $order = $this->paidOrder($customer);
        Sanctum::actingAs($customer);

        $url = $this->getJson("/api/v1/invoices/{$order->id}")->json('data.pdf_url');

        $pdf = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->get("/invoices/{$order->id}/pdf")->assertForbidden();
        $this->get(preg_replace('/signature=\w{6}/', 'signature=000000', $url))->assertForbidden();
        $this->travel(20)->minutes();
        $this->get($url)->assertForbidden();
    }
}
