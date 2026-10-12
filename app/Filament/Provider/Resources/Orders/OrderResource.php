<?php

namespace App\Filament\Provider\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Provider\Concerns\ProviderResource;
use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\Orders\Pages\ListOrders;
use App\Filament\Provider\Resources\Orders\Pages\ViewOrder;
use App\Filament\Provider\Resources\Orders\RelationManagers\BookingsRelationManager;
use App\Filament\Provider\Resources\Orders\RelationManagers\RefundsRelationManager;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Provider\Support\ProviderAccess;
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

/** Orders (payment containers) of the provider's bookings. Read-only; counter bookings are made from the schedule. */
class OrderResource extends Resource
{
    use ProviderResource;

    protected static ?string $model = Order::class;

    protected static string $area = 'orders';

    protected static ?string $viewPermission = 'orders.view';

    protected static ?string $managePermission = null;

    protected static string $langKey = 'orders';

    protected static ?string $slug = 'orders';

    protected static string|\UnitEnum|null $navigationGroup = ProviderNavGroup::Bookings;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'customer_name', 'customer_phone'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Order $record */
        return [
            __('provider.fields.customer') => $record->customerDisplayName(),
            __('provider.fields.total') => Fmt::sar($record->total),
            __('provider.fields.status') => $record->status?->getLabel(),
        ];
    }

    public static function money(): \Closure
    {
        return fn ($state) => Fmt::sar($state);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make(__('provider.orders.summary'))->columnSpan(2)->columns(3)->schema([
                TextEntry::make('number')->label(__('provider.fields.number'))->weight('bold')->copyable(),
                TextEntry::make('status')->label(__('provider.fields.status'))->badge(),
                TextEntry::make('channel')->label(__('provider.fields.channel'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => __("provider.orders.channels.{$state}"))
                    ->helperText(fn (Order $r) => $r->offline_payment_method
                        ? __('provider.fields.payment_method').': '.__("provider.orders.payment_methods.{$r->offline_payment_method}") : null),
                TextEntry::make('venue.name_ar')->formatStateUsing(Fmt::localized('venue.name'))->label(__('provider.fields.venue'))
                    ->url(fn (Order $r) => $r->venue && ! $r->venue->trashed() ? VenueResource::getUrl('view', ['record' => $r->venue_id]) : null),
                TextEntry::make('created_at')->label(__('provider.fields.created_at'))->dateTime('Y-m-d H:i'),
                TextEntry::make('confirmed_at')->label(__('provider.fields.confirmed_at'))->dateTime('Y-m-d H:i')->placeholder('-'),
                TextEntry::make('cancelled_at')->label(__('provider.fields.cancelled_at'))->dateTime('Y-m-d H:i')->placeholder('-')
                    ->helperText(fn (Order $r) => $r->cancel_reason),
                TextEntry::make('creator.name')->label(__('provider.fields.created_by'))->placeholder('-'),
                TextEntry::make('notes')->label(__('provider.fields.notes'))->placeholder('-')->columnSpanFull(),
            ]),
            Section::make(__('provider.fields.customer'))->columnSpan(1)->schema([
                TextEntry::make('customer')->label(__('provider.fields.name'))->state(fn (Order $r) => $r->customerDisplayName()),
                TextEntry::make('customer_phone_display')->label(__('provider.fields.phone'))
                    ->state(fn (Order $r) => Fmt::phone($r->user?->phone ?? $r->customer_phone))->placeholder('-'),
                TextEntry::make('customer_email_display')->label(__('provider.fields.email'))
                    ->state(fn (Order $r) => $r->user?->email ?? $r->customer_email)->placeholder('-'),
            ]),
            Section::make(__('provider.orders.amounts'))->columnSpan(2)->columns(4)->schema([
                TextEntry::make('subtotal')->label(__('provider.fields.subtotal'))->formatStateUsing(static::money()),
                TextEntry::make('vat_amount')->label(__('provider.fields.vat_amount'))->formatStateUsing(static::money()),
                TextEntry::make('total')->label(__('provider.fields.total'))->formatStateUsing(static::money())->weight('bold'),
                TextEntry::make('refunded_amount')->label(__('provider.fields.refunded_amount'))->formatStateUsing(static::money())
                    ->color(fn ($state) => $state > 0 ? 'danger' : null),
                TextEntry::make('commission_amount')->label(__('provider.fields.commission_amount'))->formatStateUsing(static::money()),
                TextEntry::make('provider_net')->label(__('provider.fields.provider_net'))->formatStateUsing(static::money())->color('success'),
            ]),
            Section::make(__('provider.orders.payment'))->columnSpan(1)->schema([
                TextEntry::make('payment.reference')->label(__('provider.fields.reference'))->placeholder('-')->copyable()->fontFamily('mono'),
                TextEntry::make('payment.method')->label(__('provider.fields.method'))->placeholder('-'),
                TextEntry::make('payment.status')->label(__('provider.fields.payment_status'))->badge()->placeholder('-'),
                TextEntry::make('payment.paid_at')->label(__('provider.fields.paid_at'))->dateTime('Y-m-d H:i')->placeholder('-'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['venue', 'user'])->withCount('bookings'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-shopping-bag')
            ->emptyStateHeading(__('provider.orders.empty'))
            ->emptyStateDescription(__('provider.orders.empty_hint'))
            ->columns([
                TextColumn::make('number')->label(__('provider.fields.number'))->searchable()->weight('bold')->copyable(),
                TextColumn::make('venue.name_ar')->visible(fn () => ProviderAccess::manyStores())->formatStateUsing(Fmt::localized('venue.name'))->label(__('provider.fields.venue'))->limit(30),
                TextColumn::make('bookings_count')->label(__('provider.fields.bookings'))->numeric()->toggleable(),
                TextColumn::make('customer')->label(__('provider.fields.customer'))
                    ->state(fn (Order $r) => $r->customerDisplayName())
                    ->description(fn (Order $r) => Fmt::phone($r->user?->phone ?? $r->customer_phone))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($w) => $w
                        ->where('customer_name', 'like', Like::contains($search))
                        ->orWhere('customer_phone', 'like', Like::contains($search))
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', Like::contains($search))->orWhere('phone', 'like', Like::contains($search))))),
                TextColumn::make('channel')->label(__('provider.fields.channel'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => __("provider.orders.channels.{$state}")),
                TextColumn::make('total')->label(__('provider.fields.total'))->formatStateUsing(static::money())->sortable(),
                TextColumn::make('provider_net')->label(__('provider.fields.provider_net'))->formatStateUsing(static::money())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label(__('provider.fields.status'))->badge(),
                TextColumn::make('created_at')->label(__('provider.fields.created_at'))->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('provider.fields.status'))->options(OrderStatus::class)->multiple(),
                SelectFilter::make('venue_id')->visible(fn () => ProviderAccess::manyStores())->label(__('provider.fields.venue'))
                    ->options(fn () => ProviderAccess::provider()?->venues()->orderBy('name_ar')->pluck('name_ar', 'id') ?? [])->searchable(),
                SelectFilter::make('channel')->label(__('provider.fields.channel'))
                    ->options(['online' => __('provider.orders.channels.online'), 'offline' => __('provider.orders.channels.offline')]),
                Filter::make('created_between')
                    ->schema([
                        DatePicker::make('from')->label(__('provider.fields.from')),
                        DatePicker::make('until')->label(__('provider.fields.until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->filtersFormColumns(2)
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [BookingsRelationManager::class, RefundsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
