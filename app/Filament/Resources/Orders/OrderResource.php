<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\LedgerEntriesRelationManager;
use App\Filament\Resources\Orders\RelationManagers\RefundsRelationManager;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Resources\Venues\VenueResource;
use App\Filament\Support\Fmt;
use App\Models\Order;
use App\Support\Like;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = Order::class;

    protected static string $permissionModule = 'orders';

    protected static string $langKey = 'orders';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'number';

    /** Orders come from the app (or the provider's offline sales); admins act through workflow actions only. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'customer_phone', 'customer_email'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['user', 'venue']);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Order $record */
        return [
            __('admin.fields.customer') => $record->customerDisplayName(),
            __('admin.fields.total') => Fmt::sar($record->total),
            __('admin.fields.status') => $record->status?->getLabel(),
        ];
    }

    public static function money(): \Closure
    {
        return fn ($state) => Fmt::sar($state);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make(__('admin.orders.summary'))->columnSpan(2)->columns(3)->schema([
                TextEntry::make('number')->label(__('admin.fields.number'))->weight('bold')->copyable(),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge(),
                TextEntry::make('channel')->label(__('admin.fields.channel'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => __("admin.orders.channels.{$state}"))
                    ->helperText(fn (Order $r) => $r->offline_payment_method ? __('admin.fields.offline_payment_method').': '.Fmt::label('payment_methods', $r->offline_payment_method) : null),
                TextEntry::make('venue.name_ar')->formatStateUsing(Fmt::localized('venue.name'))->label(__('admin.fields.venue'))
                    ->url(fn (Order $r) => VenueResource::getUrl('view', ['record' => $r->venue_id])),
                TextEntry::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))
                    ->url(fn (Order $r) => ProviderResource::getUrl('view', ['record' => $r->provider_id])),
                TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
                TextEntry::make('confirmed_at')->label(__('admin.fields.confirmed_at'))->dateTime()->placeholder('-'),
                TextEntry::make('expires_at')->label(__('admin.fields.expires_at'))->dateTime()->placeholder('-'),
                TextEntry::make('cancelled_at')->label(__('admin.fields.cancelled_at'))->dateTime()->placeholder('-')
                    ->helperText(fn (Order $r) => $r->cancel_reason),
                TextEntry::make('creator.name')->label(__('admin.fields.created_by'))->placeholder('-'),
                TextEntry::make('notes')->label(__('admin.fields.notes'))->placeholder('-')->columnSpanFull(),
            ]),
            Section::make(__('admin.fields.customer'))->columnSpan(1)->schema([
                TextEntry::make('customer')->label(__('admin.fields.name'))->state(fn (Order $r) => $r->customerDisplayName()),
                TextEntry::make('customer_phone_display')->label(__('admin.fields.phone'))
                    ->state(fn (Order $r) => Fmt::phone($r->user?->phone ?? $r->customer_phone))->placeholder('-'),
                TextEntry::make('customer_email_display')->label(__('admin.fields.email'))
                    ->state(fn (Order $r) => $r->user?->email ?? $r->customer_email)->placeholder('-'),
            ]),
            Section::make(__('admin.orders.amounts'))->columnSpan(2)->columns(4)->schema([
                TextEntry::make('subtotal')->label(__('admin.fields.subtotal'))->formatStateUsing(static::money()),
                TextEntry::make('discount')->label(__('admin.fields.discount'))->formatStateUsing(static::money()),
                TextEntry::make('vat_amount')->label(__('admin.fields.vat_amount'))->formatStateUsing(static::money()),
                TextEntry::make('total')->label(__('admin.fields.total'))->formatStateUsing(static::money())->weight('bold'),
                TextEntry::make('refunded_amount')->label(__('admin.fields.refunded_amount'))->formatStateUsing(static::money())
                    ->color(fn ($state) => $state > 0 ? 'danger' : null),
                TextEntry::make('commission_rate')->label(__('admin.fields.commission_rate'))
                    ->state(fn (Order $r) => rtrim(rtrim(number_format($r->commission_rate, 2), '0'), '.').'%'.($r->commission_fixed > 0 ? ' + '.Fmt::sar($r->commission_fixed) : '')),
                TextEntry::make('commission_amount')->label(__('admin.fields.commission_amount'))->formatStateUsing(static::money()),
                TextEntry::make('provider_net')->label(__('admin.fields.provider_net'))->formatStateUsing(static::money())->color('success'),
            ]),
            Section::make(__('admin.orders.payment'))->columnSpan(1)->schema([
                TextEntry::make('payment.reference')->label(__('admin.fields.reference'))->placeholder('-')->copyable()->fontFamily('mono')
                    ->url(fn (Order $r) => $r->payment_id ? PaymentResource::getUrl('view', ['record' => $r->payment_id]) : null),
                TextEntry::make('payment.gateway')->label(__('admin.fields.gateway'))->placeholder('-'),
                TextEntry::make('payment.method')->label(__('admin.fields.method'))->placeholder('-')->formatStateUsing(fn ($state) => Fmt::label('payment_methods', $state)),
                TextEntry::make('payment.status')->label(__('admin.fields.payment_status'))->badge()->placeholder('-'),
                TextEntry::make('payment.paid_at')->label(__('admin.fields.paid_at'))->dateTime()->placeholder('-'),
            ]),
            Section::make(__('admin.orders.accounting'))->columnSpanFull()->columns(2)->collapsible()->schema([
                TextEntry::make('accounting_ref')->label(__('admin.fields.accounting_ref'))->placeholder(__('admin.orders.not_synced')),
                TextEntry::make('accounting_synced_at')->label(__('admin.fields.accounting_synced_at'))->dateTime()->placeholder('-'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'venue', 'provider']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('admin.fields.number'))->searchable()->weight('bold')->copyable(),
                TextColumn::make('customer')->label(__('admin.fields.customer'))
                    ->state(fn (Order $r) => $r->customerDisplayName())
                    ->description(fn (Order $r) => Fmt::phone($r->user?->phone ?? $r->customer_phone))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($q) => $q
                        ->where('customer_name', 'like', Like::contains($search))
                        ->orWhere('customer_phone', 'like', Like::contains($search))
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', Like::contains($search))->orWhere('phone', 'like', Like::contains($search))))),
                TextColumn::make('venue.name_ar')->formatStateUsing(Fmt::localized('venue.name'))->label(__('admin.fields.venue'))->limit(30),
                TextColumn::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->limit(25)->toggleable(),
                TextColumn::make('total')->label(__('admin.fields.total'))->formatStateUsing(static::money())->sortable(),
                TextColumn::make('commission_amount')->label(__('admin.fields.commission'))->formatStateUsing(static::money())->sortable()->toggleable(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('channel')->label(__('admin.fields.channel'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => __("admin.orders.channels.{$state}")),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(OrderStatus::class)->multiple(),
                SelectFilter::make('channel')->label(__('admin.fields.channel'))
                    ->options(['online' => __('admin.orders.channels.online'), 'offline' => __('admin.orders.channels.offline')]),
                SelectFilter::make('provider_id')->label(__('admin.fields.provider'))->relationship('provider', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name'))->searchable(),
                SelectFilter::make('venue_id')->label(__('admin.fields.venue'))->relationship('venue', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name'))->searchable(),
                SelectFilter::make('payment_status')->label(__('admin.fields.payment_status'))->options(PaymentStatus::class)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('payment', fn ($p) => $p->where('status', $data['value']))
                        : $query),
                Filter::make('created_between')
                    ->schema([
                        DatePicker::make('from')->label(__('admin.fields.from')),
                        DatePicker::make('until')->label(__('admin.fields.to')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d)))
                    ->indicateUsing(fn (array $data) => array_values(array_filter([
                        ($data['from'] ?? null) ? __('admin.fields.from').': '.$data['from'] : null,
                        ($data['until'] ?? null) ? __('admin.fields.to').': '.$data['until'] : null,
                    ]))),
            ])
            ->filtersFormColumns(2)
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            BookingsRelationManager::class,
            RefundsRelationManager::class,
            LedgerEntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
