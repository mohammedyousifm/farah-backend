<?php

namespace App\Filament\Provider\Resources\Ledger;

use App\Enums\LedgerType;
use App\Filament\Provider\Concerns\ProviderResource;
use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\Ledger\Pages\ListLedgerEntries;
use App\Filament\Support\Fmt;
use App\Models\LedgerEntry;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The provider's wallet statement (provider account of the append-only ledger). Read-only. */
class LedgerEntryResource extends Resource
{
    use ProviderResource;

    protected static ?string $model = LedgerEntry::class;

    protected static ?string $tenantRelationshipName = 'ledgerEntries';

    protected static string $area = 'finance';

    protected static ?string $viewPermission = 'finance.view';

    protected static ?string $managePermission = null;

    protected static string $langKey = 'ledger';

    protected static ?string $slug = 'finance/ledger';

    protected static string|\UnitEnum|null $navigationGroup = ProviderNavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wallet';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('account', 'provider');
    }

    public static function table(Table $table): Table
    {
        $sum = fn () => Sum::make()->label(__('provider.fields.total'))->formatStateUsing(fn ($state) => Fmt::sar($state));

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order:id,number', 'settlement:id,number']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('provider.fields.date'))->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('type')->label(__('provider.fields.type'))->badge(),
                TextColumn::make('description')->label(__('provider.fields.description'))->limit(50)->placeholder('-')->wrap(),
                TextColumn::make('order.number')->label(__('provider.fields.order'))->placeholder('-')->fontFamily('mono'),
                TextColumn::make('amount')->label(__('provider.fields.amount'))->formatStateUsing(fn ($state) => Fmt::sar($state))
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success')->weight('bold')->sortable()
                    ->summarize($sum()),
                TextColumn::make('available_at')->label(__('provider.fields.available_at'))->dateTime('Y-m-d')->placeholder('-')
                    ->color(fn ($state) => $state && $state->isFuture() ? 'warning' : null),
                TextColumn::make('settlement.number')->label(__('provider.fields.settlement'))->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('provider.fields.type'))->options(LedgerType::class)->multiple(),
                TernaryFilter::make('settled')->label(__('provider.fields.settled'))
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('settlement_id'),
                        false: fn (Builder $q) => $q->whereNull('settlement_id'),
                    ),
                TernaryFilter::make('available')->label(__('provider.fields.funds_available'))
                    ->trueLabel(__('provider.balances.available'))->falseLabel(__('provider.balances.pending'))
                    ->queries(
                        true: fn (Builder $q) => $q->where('available_at', '<=', now()),
                        false: fn (Builder $q) => $q->where('available_at', '>', now()),
                    ),
                Filter::make('created_at')->columns(2)->columnSpan(2)
                    ->schema([
                        DatePicker::make('from')->label(__('provider.fields.from')),
                        DatePicker::make('to')->label(__('provider.fields.until')),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->filtersFormColumns(2);
    }

    public static function getPages(): array
    {
        return ['index' => ListLedgerEntries::route('/')];
    }
}
