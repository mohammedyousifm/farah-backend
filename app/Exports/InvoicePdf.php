<?php

namespace App\Exports;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

/** One invoice as an A4 PDF. mPDF shapes Arabic text correctly, as in the report exports. */
class InvoicePdf
{
    /**
     * @param  array<string, mixed>  $invoice  InvoiceService::details()
     * @param  string|null  $qr  PNG data URI of the tax QR code, when the invoice is a tax invoice
     */
    public function __construct(private array $invoice, private ?string $qr = null) {}

    public function render(): string
    {
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $rtl = app()->getLocale() === 'ar';

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'dejavusans',
            'directionality' => $rtl ? 'rtl' : 'ltr',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $tempDir,
            'margin_top' => 14,
            'margin_bottom' => 16,
            'margin_left' => 14,
            'margin_right' => 14,
        ]);

        $mpdf->SetTitle($this->invoice['number']);
        $mpdf->SetFooter(__('invoice.footer'));
        $mpdf->WriteHTML(view('invoices.pdf', ['invoice' => $this->invoice, 'qr' => $this->qr, 'rtl' => $rtl])->render());

        return $mpdf->OutputBinaryData();
    }
}
