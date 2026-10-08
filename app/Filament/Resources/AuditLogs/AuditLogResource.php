<?php

namespace App\Filament\Resources\AuditLogs;

use App\Enums\UserType;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Support\Fmt;
use App\Models\AuditLog;
use App\Models\User;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Read-only trail of every create / update / delete on audited models. */
class AuditLogResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = AuditLog::class;

    protected static string $permissionModule = 'audit_logs';

    protected static string $langKey = 'audit_logs';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::System;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-finger-print';

    protected static ?int $navigationSort = 40;

    public static function readOnly(): bool
    {
        return true;
    }

    /** @return array<string, string> morph alias => translated label */
    public static function subjectTypes(): array
    {
        return collect(array_keys(Relation::morphMap()))
            ->mapWithKeys(fn ($alias) => [$alias => __("admin.subjects.{$alias}")])->all();
    }

    public static function actionColor(?string $action): string
    {
        return match ($action) {
            'created' => 'success',
            'updated' => 'info',
            'deleted' => 'danger',
            default => 'gray',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->columnSpanFull()->schema([
                TextEntry::make('action')->label(__('admin.fields.action'))->badge()
                    ->formatStateUsing(fn ($state) => __("admin.audit_actions.{$state}"))
                    ->color(fn ($state) => static::actionColor($state)),
                TextEntry::make('auditable_type')->label(__('admin.fields.subject'))
                    ->formatStateUsing(fn ($state, AuditLog $record) => (static::subjectTypes()[$state] ?? $state).' #'.$record->auditable_id)
                    ->placeholder('-'),
                TextEntry::make('user.name')->label(__('admin.fields.user'))->placeholder(__('admin.audit.system')),
                TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
                TextEntry::make('ip')->label(__('admin.fields.ip'))->placeholder('-'),
                TextEntry::make('user_agent')->label(__('admin.fields.user_agent'))->placeholder('-')->columnSpan(3),
            ]),
            Section::make(__('admin.audit.changes'))->columnSpanFull()->schema([
                ViewEntry::make('diff')->label(__('admin.fields.changes'))->hiddenLabel()->view('filament.infolists.audit-diff'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('user.name')->label(__('admin.fields.user'))->placeholder(__('admin.audit.system'))->searchable(),
                TextColumn::make('action')->label(__('admin.fields.action'))->badge()
                    ->formatStateUsing(fn ($state) => __("admin.audit_actions.{$state}"))
                    ->color(fn ($state) => static::actionColor($state)),
                TextColumn::make('auditable_type')->label(__('admin.fields.subject'))->placeholder('-')
                    ->formatStateUsing(fn ($state) => static::subjectTypes()[$state] ?? $state),
                TextColumn::make('auditable_id')->label('#')->placeholder('-'),
                TextColumn::make('changed')->label(__('admin.audit.changed_fields'))->color('gray')->limit(60)
                    ->state(fn (AuditLog $record) => collect(array_keys(($record->new_values ?: $record->old_values) ?? []))->map(fn ($k) => Fmt::label('fields', $k))->implode('، ')),
                TextColumn::make('ip')->label(__('admin.fields.ip'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')->label(__('admin.fields.action'))
                    ->options(fn () => AuditLog::query()->distinct()->orderBy('action')->pluck('action')
                        ->mapWithKeys(fn ($a) => [$a => __("admin.audit_actions.{$a}")])->all()),
                SelectFilter::make('auditable_type')->label(__('admin.fields.subject'))->options(fn () => static::subjectTypes()),
                SelectFilter::make('user_id')->label(__('admin.fields.user'))->searchable()
                    ->options(fn () => User::where('type', UserType::Admin)->orderBy('name')->pluck('name', 'id')->all()),
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
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
