<?php

namespace App\Filament\Widgets;

use App\Enums\DocumentStatus;
use App\Filament\Support\Fmt;
use App\Models\ProviderDocument;
use App\Models\Setting;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ExpiringDocumentsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $days = (int) Setting::get('document_expiry_warning_days');

        return $table
            ->heading(__('admin.kpi.expiring_documents', ['days' => $days]))
            ->query(ProviderDocument::query()->with('provider')
                ->where('status', DocumentStatus::Approved)
                ->whereBetween('expires_at', [now()->toDateString(), now()->addDays($days)->toDateString()])
                ->orderBy('expires_at'))
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading(__('admin.kpi.no_expiring'))
            ->columns([
                TextColumn::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider')),
                TextColumn::make('type')->label(__('admin.fields.type'))->badge(),
                TextColumn::make('number')->label(__('admin.fields.document_number'))->placeholder('-'),
                TextColumn::make('expires_at')->label(__('admin.fields.expires_at'))->date()->color('warning')
                    ->description(fn (ProviderDocument $r) => $r->expires_at?->diffForHumans()),
            ]);
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can('documents.view') || $user?->can('documents.manage'));
    }
}
