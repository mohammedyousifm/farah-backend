<?php

namespace App\Filament\Pages;

use App\Exports\ReportExport;
use App\Exports\ReportPdf;
use App\Filament\NavGroup;
use App\Filament\Support\Fmt;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Sector;
use App\Models\Venue;
use App\Services\KpiService;
use App\Services\ReportService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

/**
 * RPT-01..11: filterable reports with totals and Excel / CSV / PDF export.
 *
 * @property-read Schema $form the filter form, provided by Filament from form()
 */
class Reports extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Reports;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.pages.reports';

    /** Filter state (report key, date range, dimensions). */
    public ?array $filters = [];

    /** Per-request memo of report results (not a Livewire property). */
    protected array $reportCache = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can('reports.view') || $user?->can('reports.manage'));
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.reports.title');
    }

    public function getTitle(): string
    {
        return __('admin.reports.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'report' => request()->query('report', 'sales_by_period'),
            'from' => now()->subDays(29)->toDateString(),
            'to' => now()->toDateString(),
            'group_by' => 'day',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $name = Fmt::col('name');

        return $schema
            ->statePath('filters')
            ->columns(['md' => 3, 'xl' => 6])
            ->components([
                Select::make('report')->label(__('admin.reports.report'))
                    ->options(fn () => app(ReportService::class)->reports())
                    ->required()->selectablePlaceholder(false)->live()
                    ->columnSpan(['md' => 3, 'xl' => 2]),
                DatePicker::make('from')->label(__('admin.fields.from'))->live()->maxDate(fn (Get $get) => $get('to')),
                DatePicker::make('to')->label(__('admin.fields.to'))->live()->minDate(fn (Get $get) => $get('from')),
                Select::make('group_by')->label(__('admin.reports.group_by'))
                    ->options([
                        'day' => __('admin.reports.group_day'),
                        'week' => __('admin.reports.group_week'),
                        'month' => __('admin.reports.group_month'),
                    ])
                    ->selectablePlaceholder(false)->live()
                    ->visible(fn (Get $get) => $get('report') === 'sales_by_period'),
                Select::make('provider_id')->label(__('admin.fields.provider'))
                    ->options(fn () => Provider::orderBy('name_ar')->pluck($name, 'id')->filter())
                    ->searchable()->live(),
                Select::make('venue_id')->label(__('admin.fields.venue'))
                    ->options(fn (Get $get) => Venue::query()
                        ->when($get('provider_id'), fn ($q, $id) => $q->where('provider_id', $id))
                        ->orderBy('name_ar')->limit(300)->get()->mapWithKeys(fn (Venue $v) => [$v->id => $v->tr('name')]))
                    ->searchable()->live()
                    ->visible(fn (Get $get) => ! in_array($get('report'), ['settlements', 'providers'], true)),
                Select::make('sector_id')->label(__('admin.fields.sector'))
                    ->options(fn () => Sector::orderBy('sort_order')->pluck($name, 'id'))
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('category_id', null)),
                Select::make('category_id')->label(__('admin.fields.category'))
                    ->options(fn (Get $get) => Category::query()
                        ->when($get('sector_id'), fn ($q, $id) => $q->where('sector_id', $id))
                        ->orderBy('sort_order')->pluck($name, 'id'))
                    ->searchable()->live()
                    ->visible(fn (Get $get) => ! in_array($get('report'), ['settlements', 'providers'], true)),
            ]);
    }

    /** Current report result, computed once per request. */
    public function getReport(): array
    {
        $hash = md5(json_encode([$this->reportKey(), $this->filters, app()->getLocale()]));

        return $this->reportCache[$hash] ??= app(ReportService::class)->run($this->reportKey(), $this->filters ?? []);
    }

    public function reportKey(): string
    {
        $key = $this->filters['report'] ?? 'sales_by_period';

        return in_array($key, ReportService::REPORTS, true) ? $key : 'sales_by_period';
    }

    public function formatCell(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (in_array($key, $this->getReport()['money'] ?? [], true) && is_numeric($value)) {
            return Fmt::sar($value);
        }

        return is_int($value) ? number_format($value) : (string) $value;
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('export_xlsx')->label(__('admin.reports.export_xlsx'))->icon('heroicon-o-table-cells')
                    ->action(fn () => $this->exportExcel(ExcelWriter::XLSX, 'xlsx')),
                Action::make('export_csv')->label(__('admin.reports.export_csv'))->icon('heroicon-o-document-text')
                    ->action(fn () => $this->exportExcel(ExcelWriter::CSV, 'csv')),
                Action::make('export_pdf')->label(__('admin.reports.export_pdf'))->icon('heroicon-o-document-arrow-down')
                    ->action(fn () => (new ReportPdf($this->getReport(), $this->reportTitle(), $this->meta()))->download($this->filename('pdf'))),
            ])
                ->label(__('admin.reports.export'))
                ->icon('heroicon-o-arrow-down-tray')
                ->button()
                ->color('primary'),
        ];
    }

    private function exportExcel(string $writer, string $extension)
    {
        return Excel::download(new ReportExport($this->getReport(), $this->reportTitle()), $this->filename($extension), $writer);
    }

    public function reportTitle(): string
    {
        return app(ReportService::class)->title($this->reportKey());
    }

    /** Period and active filters shown in the page subtitle and on the PDF. */
    public function meta(): array
    {
        [$from, $to] = KpiService::range($this->filters['from'] ?? null, $this->filters['to'] ?? null);
        $f = $this->filters ?? [];

        $filters = array_filter([
            __('admin.fields.provider') => filled($f['provider_id'] ?? null) ? Provider::find($f['provider_id'])?->displayName() : null,
            __('admin.fields.venue') => filled($f['venue_id'] ?? null) ? Venue::find($f['venue_id'])?->tr('name') : null,
            __('admin.fields.sector') => filled($f['sector_id'] ?? null) ? Sector::find($f['sector_id'])?->tr('name') : null,
            __('admin.fields.category') => filled($f['category_id'] ?? null) ? Category::find($f['category_id'])?->tr('name') : null,
        ]);

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'filters' => $filters];
    }

    private function filename(string $extension): string
    {
        $meta = $this->meta();

        return "farah-{$this->reportKey()}-{$meta['from']}_{$meta['to']}.{$extension}";
    }
}
