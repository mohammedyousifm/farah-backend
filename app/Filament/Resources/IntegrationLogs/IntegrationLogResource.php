<?php

namespace App\Filament\Resources\IntegrationLogs;

use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\IntegrationLogs\Pages\ListIntegrationLogs;
use App\Filament\Resources\IntegrationLogs\Pages\ViewIntegrationLog;
use App\Models\IntegrationLog;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Read-only log of calls to external services (payment, SMS, accounting, push). */
class IntegrationLogResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = IntegrationLog::class;

    protected static string $permissionModule = 'integrations';

    protected static string $langKey = 'integration_logs';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::System;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 50;

    public static function readOnly(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public static function services(): array
    {
        return collect(['payment', 'sms', 'accounting', 'push', 'maps'])
            ->mapWithKeys(fn ($s) => [$s => __("admin.services.{$s}")])->all();
    }

    public static function outcome(mixed $success): string
    {
        return $success ? __('admin.payload.success') : __('admin.payload.failed');
    }

    /** "<1 ms" for a call faster than a millisecond (the column stores whole ms), "Not recorded" when missing. */
    public static function duration(?int $ms): string
    {
        return match (true) {
            $ms === null => __('admin.payload.not_recorded'),
            $ms < 1 => '<1 ms',
            $ms >= 1000 => number_format($ms / 1000, 2).' s',
            default => number_format($ms).' ms',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make()->columns(4)->columnSpanFull()->schema([
                TextEntry::make('service')->label(__('admin.fields.service'))->badge()
                    ->formatStateUsing(fn ($state) => static::services()[$state] ?? $state),
                TextEntry::make('action')->label(__('admin.fields.action'))->fontFamily('mono'),
                TextEntry::make('success')->label(__('admin.fields.success'))->badge()
                    ->formatStateUsing(fn ($state) => static::outcome($state))
                    ->color(fn ($state) => $state ? 'success' : 'danger')
                    ->icon(fn ($state) => $state ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle'),
                TextEntry::make('duration_ms')->label(__('admin.fields.duration'))
                    ->state(fn (IntegrationLog $record) => static::duration($record->duration_ms)),
                TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
            ]),
            // Side by side on wide screens, one under the other on phones (the schema's 2 columns start at "lg").
            Section::make(__('admin.fields.request'))->columnSpan(1)->schema([
                ViewEntry::make('request')->label(__('admin.fields.request'))->hiddenLabel()->view('filament.infolists.payload'),
            ]),
            Section::make(__('admin.fields.response'))->columnSpan(1)->schema([
                ViewEntry::make('response')->label(__('admin.fields.response'))->hiddenLabel()->view('filament.infolists.payload'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('service')->label(__('admin.fields.service'))->badge()
                    ->formatStateUsing(fn ($state) => static::services()[$state] ?? $state),
                TextColumn::make('action')->label(__('admin.fields.action'))->fontFamily('mono')->searchable(),
                TextColumn::make('success')->label(__('admin.fields.success'))->badge()
                    ->formatStateUsing(fn ($state) => static::outcome($state))
                    ->color(fn ($state) => $state ? 'success' : 'danger'),
                TextColumn::make('duration_ms')->label(__('admin.fields.duration'))->sortable()
                    ->state(fn (IntegrationLog $record) => static::duration($record->duration_ms)),
            ])
            ->filters([
                SelectFilter::make('service')->label(__('admin.fields.service'))->options(static::services()),
                TernaryFilter::make('success')->label(__('admin.fields.success')),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('admin.fields.from')),
                        DatePicker::make('to')->label(__('admin.fields.to')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIntegrationLogs::route('/'),
            'view' => ViewIntegrationLog::route('/{record}'),
        ];
    }
}
