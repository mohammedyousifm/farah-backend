<?php

namespace App\Filament\Pages;

use App\Enums\LedgerType;
use App\Enums\ProviderStatus;
use App\Filament\NavGroup;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\Provider;
use App\Services\SettlementService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Provider balances from the ledger (held / available / in settlement), one query with sub-selects. */
class ProviderBalances extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 55;

    protected static ?string $slug = 'provider-balances';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can('settlements.view') || $user?->can('settlements.manage')
            || $user?->can('ledger.view') || $user?->can('ledger.manage'));
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.balances.title');
    }

    public function getTitle(): string
    {
        return __('admin.balances.title');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $provider = fn ($q) => $q->where('account', 'provider');
        $money = fn ($state) => Fmt::sar($state);
        $sum = fn () => Sum::make()->formatStateUsing(fn ($state) => Fmt::sar($state));

        return $table
            ->modelLabel(__('admin.resources.providers.singular'))
            ->pluralModelLabel(__('admin.resources.providers.plural'))
            ->query(fn () => Provider::query()
                ->where('status', ProviderStatus::Approved)
                ->withSum(['ledgerEntries as pending_balance' => fn ($q) => $provider($q)
                    ->whereNull('settlement_id')->where('available_at', '>', now())], 'amount')
                ->withSum(['ledgerEntries as available_balance' => fn ($q) => $provider($q)
                    ->whereNull('settlement_id')->where('available_at', '<=', now())], 'amount')
                ->withSum(['ledgerEntries as in_settlement_balance' => fn ($q) => $provider($q)
                    ->whereNotNull('settlement_id')->where('type', '!=', LedgerType::Payout)
                    ->whereHas('settlement', fn ($s) => $s->whereIn('status', ['draft', 'pending_approval', 'approved']))], 'amount')
                ->withSum(['ledgerEntries as paid_out_balance' => fn ($q) => $provider($q)
                    ->where('type', LedgerType::Payout)], 'amount'))
            ->defaultSort('available_balance', 'desc')
            ->columns([
                TextColumn::make('name_ar')->formatStateUsing(Fmt::localized('name'))->label(__('admin.fields.provider'))->searchable(['name_ar', 'name_en'])->weight('bold')
                    ->description(fn (Provider $r) => $r->iban ?: __('admin.settlements.missing_iban')),
                TextColumn::make('pending_balance')->label(__('admin.balances.pending'))->formatStateUsing($money)->sortable()
                    ->color('warning')->summarize($sum()),
                TextColumn::make('available_balance')->label(__('admin.balances.available'))->formatStateUsing($money)->sortable()
                    ->weight('bold')->color('success')->summarize($sum()),
                TextColumn::make('in_settlement_balance')->label(__('admin.balances.in_settlement'))->formatStateUsing($money)->sortable()
                    ->summarize($sum()),
                TextColumn::make('paid_out_balance')->label(__('admin.balances.paid_out'))->formatStateUsing(fn ($state) => Fmt::sar(-(float) $state))
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('has_available')->label(__('admin.balances.has_available'))
                    ->queries(
                        true: fn (Builder $q) => $q->having('available_balance', '>', 0),
                        false: fn (Builder $q) => $q->having('available_balance', '<=', 0),
                    ),
            ])
            ->recordUrl(fn (Provider $record) => ProviderResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                Action::make('generate_settlement')->label(__('admin.actions.generate_settlement'))->icon('heroicon-o-document-currency-dollar')
                    ->visible(fn () => Act::can('settlements.manage'))
                    ->schema([
                        DatePicker::make('until')->label(__('admin.fields.until'))->default(now()->toDateString())->required(),
                        Toggle::make('enforce_minimum')->label(__('admin.fields.enforce_minimum'))->default(true),
                    ])
                    ->action(fn (Provider $record, array $data) => Act::run(fn () => app(SettlementService::class)->generate(
                        $record, Carbon::parse($data['until']), auth()->user(), (bool) $data['enforce_minimum'],
                    ))),
            ]);
    }
}
