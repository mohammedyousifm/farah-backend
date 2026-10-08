<?php

namespace App\Exports;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PDF export of a ReportService result. mPDF shapes Arabic text correctly
 * (autoScriptToLang + autoLangToFont) and lays the page out right-to-left.
 */
class ReportPdf
{
    public function __construct(private array $report, private string $title, private array $meta = []) {}

    public function render(): string
    {
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $rtl = app()->getLocale() === 'ar';

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'default_font' => 'dejavusans',
            'directionality' => $rtl ? 'rtl' : 'ltr',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $tempDir,
            'margin_top' => 12,
            'margin_bottom' => 14,
            'margin_left' => 10,
            'margin_right' => 10,
        ]);

        $mpdf->SetTitle($this->title);
        $mpdf->SetFooter('{PAGENO} / {nbpg}');
        $mpdf->WriteHTML(view('filament.reports.pdf', [
            'report' => $this->report,
            'title' => $this->title,
            'meta' => $this->meta,
            'rtl' => $rtl,
        ])->render());

        return $mpdf->Output('', 'S');
    }

    public function download(string $filename): StreamedResponse
    {
        $content = $this->render();

        return response()->streamDownload(fn () => print ($content), $filename, ['Content-Type' => 'application/pdf']);
    }
}
