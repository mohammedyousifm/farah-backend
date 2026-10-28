<?php

namespace App\Services;

use App\Exports\InvoicePdf;
use App\Models\Booking;
use App\Models\Order;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * The customer's invoice for a paid order.
 *
 * The seller is the provider, and Farah issues the invoice on the provider's behalf. A provider
 * with a VAT number gets a simplified tax invoice with the QR code ZATCA asks for (phase one:
 * seller, VAT number, time, total, VAT). A provider without one is not VAT-registered, so its
 * invoice is a plain invoice: no VAT line and no QR code.
 *
 * Nothing is stored except the number and its date: an invoice is always rebuilt from the order,
 * whose amounts are snapshots taken at payment and never edited afterwards.
 */
class InvoiceService
{
    /** Relations an invoice reads. */
    public const RELATIONS = ['provider.city', 'venue.city', 'user', 'bookings.activity', 'bookings.resource'];

    /** How long a PDF link works. It is opened outside the app, so it carries no sign-in token. */
    private const PDF_LINK_MINUTES = 15;

    /**
     * Gives a paid order its invoice number: the next one of the year, without gaps.
     * Safe to call again; an order keeps the number it has.
     */
    public function issue(Order $order): Order
    {
        if ($order->invoice_number || ! $order->isPaid()) {
            return $order;
        }

        DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $locked->invoice_number) {
                $order->setRawAttributes($locked?->getAttributes() ?? $order->getAttributes(), true);

                return;
            }

            $issuedAt = $locked->confirmed_at ?? now();
            // The invoice year is the year on the business clock (an order paid at 01:00 on 1 January is next year's).
            $year = (int) $issuedAt->copy()->setTimezone(AvailabilityService::timezone())->format('Y');

            DB::table('invoice_sequences')->insertOrIgnore(['year' => $year, 'last' => 0]);
            $next = (int) DB::table('invoice_sequences')->where('year', $year)->lockForUpdate()->value('last') + 1;
            DB::table('invoice_sequences')->where('year', $year)->update(['last' => $next]);

            $order->forceFill([
                'invoice_number' => sprintf('INV-%d-%06d', $year, $next),
                'invoice_issued_at' => $issuedAt,
            ])->save();
        });

        return $order;
    }

    /** Short form for the list. */
    public function summary(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->invoice_number,
            'order_number' => $order->number,
            'issued_at' => $order->invoice_issued_at?->toIso8601String(),
            'is_tax_invoice' => $this->isTaxInvoice($order),
            'seller_name' => $this->sellerName($order),
            'venue_name' => $order->venue?->tr('name'),
            'total' => (float) $order->total,
            'currency' => $order->currency,
        ];
    }

    /** Everything printed on the invoice. */
    public function details(Order $order): array
    {
        $order->loadMissing(self::RELATIONS);

        $taxed = $this->isTaxInvoice($order);
        $total = (float) $order->total;
        $vat = $taxed ? (float) $order->vat_amount : 0.0;
        $net = round($total - $vat, 2);
        $provider = $order->provider;

        return [
            ...$this->summary($order),
            'seller' => [
                'name' => $this->sellerName($order),
                'vat_number' => $taxed ? $provider->vat_number : null,
                'cr_number' => $provider?->cr_number,
                'address' => collect([$provider?->address, $provider?->city?->tr('name')])->filter()->implode('، ') ?: null,
            ],
            'buyer' => [
                'name' => $order->customer_name ?: $order->user?->name,
                'phone' => $order->customer_phone ?: $order->user?->phone,
            ],
            'lines' => $order->bookings->map(fn (Booking $booking) => [
                'description' => collect([$booking->activity?->tr('name') ?? $booking->name, $booking->resource?->tr('name')])->filter()->unique()->implode(' · '),
                'starts_at' => $booking->starts_at?->toIso8601String(),
                'ends_at' => $booking->ends_at?->toIso8601String(),
                'quantity' => (int) $booking->slots,
                'unit_price' => (float) $booking->unit_price,
                'total' => (float) $booking->total,
            ])->values()->all(),
            'discount' => (float) $order->discount,
            'net' => $net,
            'vat_rate' => $taxed && $net > 0 ? (int) round($vat / $net * 100) : 0,
            'vat_amount' => $vat,
            'refunded_amount' => (float) $order->refunded_amount,
            'qr' => $taxed ? $this->qrPayload($order) : null,
            'pdf_url' => $this->pdfUrl($order),
        ];
    }

    public function isTaxInvoice(Order $order): bool
    {
        return filled($order->provider?->vat_number);
    }

    /**
     * ZATCA phase-one QR content: five tag-length-value fields, Base64 encoded.
     * 1 seller name, 2 VAT number, 3 invoice time, 4 total with VAT, 5 VAT amount.
     */
    public function qrPayload(Order $order): string
    {
        $fields = [
            1 => $this->sellerName($order),
            2 => (string) $order->provider?->vat_number,
            3 => ($order->invoice_issued_at ?? $order->confirmed_at ?? $order->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            4 => number_format((float) $order->total, 2, '.', ''),
            5 => number_format((float) $order->vat_amount, 2, '.', ''),
        ];

        $tlv = '';
        foreach ($fields as $tag => $value) {
            $tlv .= chr($tag).chr(strlen($value)).$value; // strlen: the length is in bytes, as the standard asks
        }

        return base64_encode($tlv);
    }

    /** The QR code as a PNG data URI, for the PDF. */
    public function qrImage(Order $order): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => true,
            'scale' => 6,
            'quietzoneSize' => 2,
        ])))->render($this->qrPayload($order));
    }

    public function pdf(Order $order): string
    {
        $details = $this->details($order);

        return (new InvoicePdf($details, $details['qr'] ? $this->qrImage($order) : null))->render();
    }

    public function pdfUrl(Order $order): string
    {
        return URL::temporarySignedRoute('invoices.pdf', now()->addMinutes(self::PDF_LINK_MINUTES), [
            'order' => $order->id,
            'lang' => app()->getLocale(),
        ]);
    }

    /** The name on the commercial register when there is one, else the name customers know. */
    private function sellerName(Order $order): string
    {
        return (string) ($order->provider?->legal_name ?: $order->provider?->displayName());
    }
}
