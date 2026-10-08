<?php

namespace App\Filament\Resources\Bookings;

use App\Enums\BookingStatus;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Resources\Venues\VenueResource;
use App\Filament\Support\Fmt;
use App\Models\Booking;
use Carbon\CarbonImmutable;
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

/** Every reserved resource / time window on the platform. Read-only: bookings change through their order. */
class BookingResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = Booking::class;

    protected static string $permissionModule = 'orders';

    protected static string $langKey = 'bookings';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'code';

    public static function readOnly(): bool
    {
        return true;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['venue', 'user', 'order']);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Booking $record */
        return array_filter([
            __('admin.fields.venue') => $record->venue?->tr('name'),
            __('admin.fields.time_window') => static::window($record),
            __('admin.fields.status') => $record->status?->getLabel(),
        ]);
    }

    /** Bookings are played on the venue's clock, so times are always shown in the business timezone. */
    public static function timezone(): string
    {
        return config('farah.timezone', 'Asia/Riyadh');
    }

    /** "2026-10-08 20:00 → 22:00" in the business timezone. */
    public static function window(Booking $booking): string
    {
        $start = $booking->starts_at->timezone(static::timezone());
        $end = $booking->ends_at->timezone(static::timezone());

        return $start->format('Y-m-d H:i').' → '.$end->format($end->isSameDay($start) ? 'H:i' : 'Y-m-d H:i');
    }

    public static function customer(Booking $booking): string
    {
        return $booking->user?->name ?? $booking->order?->customer_name ?? '-';
    }

    /**
     * Table columns shared with the bookings relation managers.
     *
     * @return array<int, TextColumn>
     */
    public static function columns(bool $venue = true, bool $order = true): array
    {
        return array_values(array_filter([
            TextColumn::make('code')->label(__('admin.fields.code'))->fontFamily('mono')->copyable()->searchable()->weight('bold'),
            $venue ? TextColumn::make('venue.name_ar')->formatStateUsing(Fmt::localized('venue.name'))->label(__('admin.fields.venue'))->limit(30)->searchable() : null,
            TextColumn::make('activity.name_ar')->formatStateUsing(Fmt::localized('activity.name'))->label(__('admin.fields.activity'))
                ->description(fn (Booking $r) => $r->resource?->tr('name')),
            TextColumn::make('customer')->label(__('admin.fields.customer'))
                ->state(fn (Booking $r) => static::customer($r))
                ->description(fn (Booking $r) => Fmt::phone($r->user?->phone ?? $r->order?->customer_phone)),
            TextColumn::make('starts_at')->label(__('admin.fields.time_window'))->sortable()
                ->formatStateUsing(fn (Booking $r) => static::window($r))
                ->description(fn (Booking $r) => __('admin.bookings.duration', ['slots' => $r->slots, 'minutes' => $r->slots * $r->slot_minutes])),
            TextColumn::make('total')->label(__('admin.fields.total'))->formatStateUsing(fn ($state) => Fmt::sar($state))->sortable(),
            TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
            $order ? TextColumn::make('order.number')->label(__('admin.fields.order'))->searchable()
                ->url(fn (Booking $r) => OrderResource::canViewAny() ? OrderResource::getUrl('view', ['record' => $r->order_id]) : null) : null,
        ]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make(__('admin.bookings.summary'))->columnSpan(2)->columns(3)->schema([
                TextEntry::make('code')->label(__('admin.fields.code'))->weight('bold')->fontFamily('mono')->copyable(),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge(),
                TextEntry::make('order.number')->label(__('admin.fields.order'))
                    ->url(fn (Booking $r) => OrderResource::canViewAny() ? OrderResource::getUrl('view', ['record' => $r->order_id]) : null)
                    ->helperText(fn (Booking $r) => $r->order?->status?->getLabel()),
                TextEntry::make('venue.name_ar')->formatStateUsing(Fmt::localized('venue.name'))->label(__('admin.fields.venue'))
                    ->url(fn (Booking $r) => VenueResource::canViewAny() ? VenueResource::getUrl('view', ['record' => $r->venue_id]) : null),
                TextEntry::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))
                    ->url(fn (Booking $r) => ProviderResource::canViewAny() ? ProviderResource::getUrl('view', ['record' => $r->provider_id]) : null),
                TextEntry::make('name')->label(__('admin.fields.booked_as')),
                TextEntry::make('activity.name_ar')->formatStateUsing(Fmt::localized('activity.name'))->label(__('admin.fields.activity')),
                TextEntry::make('resource.name_ar')->formatStateUsing(Fmt::localized('resource.name'))->label(__('admin.fields.resource')),
                TextEntry::make('window')->label(__('admin.fields.time_window'))->state(fn (Booking $r) => static::window($r))
                    ->helperText(static::timezone()),
                TextEntry::make('slots')->label(__('admin.fields.slots'))
                    ->formatStateUsing(fn ($state, Booking $r) => __('admin.bookings.duration', ['slots' => $state, 'minutes' => $state * $r->slot_minutes])),
                TextEntry::make('unit_price')->label(__('admin.fields.slot_price'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextEntry::make('total')->label(__('admin.fields.total'))->formatStateUsing(fn ($state) => Fmt::sar($state))->weight('bold'),
            ]),
            Section::make(__('admin.fields.customer'))->columnSpan(1)->schema([
                TextEntry::make('customer')->label(__('admin.fields.name'))->state(fn (Booking $r) => static::customer($r)),
                TextEntry::make('customer_phone')->label(__('admin.fields.phone'))
                    ->state(fn (Booking $r) => Fmt::phone($r->user?->phone ?? $r->order?->customer_phone))->placeholder('-'),
                TextEntry::make('checked_in_at')->label(__('admin.fields.checked_in_at'))->dateTime(timezone: static::timezone())->placeholder('-')
                    ->helperText(fn (Booking $r) => $r->checkedInBy?->name),
                TextEntry::make('cancelled_at')->label(__('admin.fields.cancelled_at'))->dateTime(timezone: static::timezone())->placeholder('-'),
                TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(timezone: static::timezone()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['venue', 'activity', 'resource', 'user', 'order']))
            ->defaultSort('starts_at', 'desc')
            ->columns(static::columns())
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(BookingStatus::class)->multiple(),
                SelectFilter::make('venue_id')->label(__('admin.fields.venue'))->relationship('venue', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name'))->searchable(),
                SelectFilter::make('provider_id')->label(__('admin.fields.provider'))->relationship('provider', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name'))->searchable(),
                // Days are days on the venue's clock, so the bounds are converted to UTC.
                Filter::make('played_between')
                    ->schema([
                        DatePicker::make('from')->label(__('admin.fields.from')),
                        DatePicker::make('until')->label(__('admin.fields.to')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('starts_at', '>=', CarbonImmutable::parse($d, static::timezone())->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('starts_at', '<=', CarbonImmutable::parse($d, static::timezone())->endOfDay()->utc())))
                    ->indicateUsing(fn (array $data) => array_values(array_filter([
                        ($data['from'] ?? null) ? __('admin.fields.from').': '.$data['from'] : null,
                        ($data['until'] ?? null) ? __('admin.fields.to').': '.$data['until'] : null,
                    ]))),
            ])
            ->filtersFormColumns(2)
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
            'view' => ViewBooking::route('/{record}'),
        ];
    }
}
