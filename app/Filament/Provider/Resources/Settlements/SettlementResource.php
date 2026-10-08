<?php

namespace App\Filament\Provider\Resources\Settlements;

use App\Enums\SettlementStatus;
use App\Filament\Provider\Concerns\ProviderResource;
use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\Settlements\Pages\ListSettlements;
use App\Filament\Provider\Resources\Settlements\Pages\ViewSettlement;
use App\Filament\Provider\Resources\Settlements\RelationManagers\EntriesRelationManager;
use App\Filament\Support\Fmt;
use App\Models\Settlement;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/** Payout statements prepared by the platform (read-only for the provider). */
class SettlementResource extends Resource
{
    use ProviderResource;

    protected static ?string $model = Settlement::class;

    protected static string $area = 'finance';

    protected static ?string $viewPermission = 'finance.view';

    protected static ?string $managePermission = null;

    protected static string $langKey = 'settlements';

    protected static ?string $slug = 'finance/settlements';

    protected static string|\UnitEnum|null $navigationGroup = ProviderNavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function infolist(Schema $schema): Schema
    {
        $money = fn ($state) => Fmt::sar($state);

        return $schema->components([
            Section::make()->columnSpanFull()->columns(3)->schema([
                TextEntry::make('number')->label(__('provider.fields.number'))->weight('bold')->copyable(),
                TextEntry::make('status')->label(__('provider.fields.status'))->badge(),
                TextEntry::make('period')->label(__('provider.fields.period'))
                    ->state(fn (Settlement $r) => $r->period_from?->toDateString().' → '.$r->period_to?->toDateString()),
            ]),
            Section::make(__('provider.settlements.breakdown'))->columns(5)->columnSpanFull()->schema([
                TextEntry::make('gross_amount')->label(__('provider.fields.gross_amount'))->formatStateUsing($money),
                TextEntry::make('commission_amount')->label(__('provider.fields.commission'))->formatStateUsing($money)->color('danger'),
                TextEntry::make('refunds_amount')->label(__('provider.fields.refunds_amount'))->formatStateUsing($money)->color('danger'),
                TextEntry::make('adjustments_amount')->label(__('provider.fields.adjustments_amount'))->formatStateUsing($money),
                TextEntry::make('net_amount')->label(__('provider.fields.net_amount'))->formatStateUsing($money)->weight('bold')->color('success')->size('lg'),
            ]),
            Section::make(__('provider.settlements.payment'))->columns(3)->columnSpanFull()->schema([
                TextEntry::make('bank_snapshot.iban')->label(__('provider.fields.iban'))->placeholder('-')->fontFamily('mono'),
                TextEntry::make('paid_at')->label(__('provider.fields.paid_at'))->dateTime('Y-m-d H:i')->placeholder('-'),
                TextEntry::make('payment_reference')->label(__('provider.fields.payment_reference'))->placeholder('-')->copyable(),
                TextEntry::make('receipt_path')->label(__('provider.fields.receipt'))->placeholder('-')
                    ->formatStateUsing(fn () => __('provider.actions.open_file'))
                    ->url(fn (Settlement $r) => $r->receipt_path ? Storage::disk('public')->url($r->receipt_path) : null, true)
                    ->color('primary'),
                TextEntry::make('notes')->label(__('provider.fields.notes'))->placeholder('-')->columnSpan(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state) => Fmt::sar($state);

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('provider.fields.number'))->weight('bold')->searchable(),
                TextColumn::make('period')->label(__('provider.fields.period'))
                    ->state(fn (Settlement $r) => $r->period_from?->toDateString().' → '.$r->period_to?->toDateString()),
                TextColumn::make('gross_amount')->label(__('provider.fields.gross_amount'))->formatStateUsing($money),
                TextColumn::make('commission_amount')->label(__('provider.fields.commission'))->formatStateUsing($money),
                TextColumn::make('net_amount')->label(__('provider.fields.net_amount'))->formatStateUsing($money)->weight('bold'),
                TextColumn::make('status')->label(__('provider.fields.status'))->badge(),
                TextColumn::make('paid_at')->label(__('provider.fields.paid_at'))->date('Y-m-d')->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('provider.fields.status'))->options(SettlementStatus::class),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [EntriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSettlements::route('/'),
            'view' => ViewSettlement::route('/{record}'),
        ];
    }
}
