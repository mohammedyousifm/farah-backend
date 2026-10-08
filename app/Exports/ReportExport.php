<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/** Excel / CSV export of any ReportService result (headings, rows, totals row). */
class ReportExport implements FromArray, ShouldAutoSize, WithCustomCsvSettings, WithEvents, WithHeadings, WithTitle
{
    public function __construct(private array $report, private string $title) {}

    public function headings(): array
    {
        return array_values($this->report['columns']);
    }

    public function array(): array
    {
        $keys = array_keys($this->report['columns']);
        $line = fn (array $row) => array_map(fn ($k) => $row[$k] ?? null, $keys);

        $rows = array_map($line, $this->report['rows']);
        if ($this->report['totals']) {
            $rows[] = $line($this->report['totals']);
        }

        return $rows;
    }

    public function title(): string
    {
        // Sheet titles are limited to 31 chars and cannot contain some symbols.
        return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $this->title), 0, 31);
    }

    public function getCsvSettings(): array
    {
        return ['use_bom' => true]; // so Excel opens Arabic CSV as UTF-8
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->getSheet()->getDelegate();
                $sheet->setRightToLeft(app()->getLocale() === 'ar');

                $lastColumn = $sheet->getHighestColumn();
                $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);

                if ($this->report['totals']) {
                    $lastRow = $sheet->getHighestRow();
                    $sheet->getStyle("A{$lastRow}:{$lastColumn}{$lastRow}")->getFont()->setBold(true);
                }
            },
        ];
    }
}
