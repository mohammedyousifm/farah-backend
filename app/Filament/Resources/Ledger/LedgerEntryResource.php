<?php

namespace App\Filament\Resources\Ledger;

use App\Enums\LedgerType;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Ledger\Pages\ListLedgerEntries;
use App\Filament\Resources\Ledger\Pages\ViewLedgerEntry;
use App\Filament\Support\Fmt;
use App\Models\LedgerEntry;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Append-only ledger: read-only, plus a manual adjustment action on the list page. */
class LedgerEntryResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = LedgerEntry::class;

    protected static string $permissionModule = 'ledger';

    protected static string $langKey = 'ledger';

    protected static ?string $slug = 'ledger';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 60;

    public static function readOnly(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public static function accountOptions(): array
    {
        return ['provider' => __('admin.accounts.provider'), 'platform' => __('admin.accounts.platform')];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('id')->label('#'),
            TextEntry::make('account')->label(__('admin.fields.account'))->formatStateUsing(fn ($state) => __("admin.accounts.{$state}")),
            TextEntry::make('type')->label(__('admin.fields.type'))->badge(),
            TextEntry::make('amount')->label(__('admin.fields.amount'))->formatStateUsing(fn ($state) => Fmt::sar($state))
                ->color(fn ($state) => $state < 0 ? 'danger' : 'success')->weight('bold'),
            TextEntry::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->placeholder('-'),
            TextEntry::make('order.number')->label(__('admin.fields.order'))->placeholder('-'),
            TextEntry::make('refund.number')->label(__('admin.fields.refund'))->placeholder('-'),
            TextEntry::make('settlement.number')->label(__('admin.fields.settlement'))->placeholder('-'),
            TextEntry::make('available_at')->label(__('admin.fields.available_at'))->dateTime()->placeholder('-'),
            TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
            TextEntry::make('creator.name')->label(__('admin.fields.created_by'))->placeholder(__('admin.system')),
            TextEntry::make('description')->label(__('admin.fields.description'))->placeholder('-')->columnSpanFull(),
        ]);
    }

    /** Columns and filters shared with the settlement entries relation manager. */
    public static function columns(bool $withProvider = true): array
    {
        return array_values(array_filter([
            TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
            TextColumn::make('account')->label(__('admin.fields.account'))->badge()->color('gray')
                ->formatStateUsing(fn ($state) => __("admin.accounts.{$state}")),
            TextColumn::make('type')->label(__('admin.fields.type'))->badge(),
            $withProvider ? TextColumn::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->placeholder('-')->limit(25)->searchable() : null,
            TextColumn::make('order.number')->label(__('admin.fields.order'))->placeholder('-')->searchable(),
            TextColumn::make('settlement.number')->label(__('admin.fields.settlement'))->placeholder('-')->toggleable(),
            TextColumn::make('amount')->label(__('admin.fields.amount'))
                ->formatStateUsing(fn ($state) => Fmt::sar($state))
                ->color(fn ($state) => $state < 0 ? 'danger' : 'success')
                ->summarize(Sum::make()->label(__('admin.fields.total'))->formatStateUsing(fn ($state) => Fmt::sar($state))),
            TextColumn::make('description')->label(__('admin.fields.description'))->limit(40)->placeholder('-')->wrap(),
            TextColumn::make('available_at')->label(__('admin.fields.available_at'))->dateTime()->placeholder('-')->toggleable(),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['provider', 'order', 'settlement']))
            ->defaultSort('id', 'desc')
            ->columns(static::columns())
            ->filters([
                SelectFilter::make('account')->label(__('admin.fields.account'))->options(static::accountOptions()),
                SelectFilter::make('type')->label(__('admin.fields.type'))->options(LedgerType::class)->multiple(),
                SelectFilter::make('provider_id')->label(__('admin.fields.provider'))->relationship('provider', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name'))->searchable(),
                TernaryFilter::make('settled')->label(__('admin.fields.settled'))
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('settlement_id'),
                        false: fn (Builder $q) => $q->whereNull('settlement_id'),
                    ),
                TernaryFilter::make('available')->label(__('admin.fields.funds_available'))
                    ->trueLabel(__('admin.ledger.available'))->falseLabel(__('admin.ledger.held'))
                    ->queries(
                        true: fn (Builder $q) => $q->where('available_at', '<=', now()),
                        false: fn (Builder $q) => $q->where('available_at', '>', now()),
                    ),
                Filter::make('created_at')->columns(2)->columnSpan(2)
                    ->schema([
                        DatePicker::make('from')->label(__('admin.fields.from')),
                        DatePicker::make('to')->label(__('admin.fields.to')),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerEntries::route('/'),
            'view' => ViewLedgerEntry::route('/{record}'),
        ];
    }
}
