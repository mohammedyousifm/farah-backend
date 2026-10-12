<?php

namespace App\Filament\Provider\Resources\Bookings;

use App\Enums\BookingStatus;
use App\Filament\Provider\Concerns\ProviderResource;
use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\Bookings\Pages\ListBookings;
use App\Filament\Provider\Resources\Orders\OrderResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\Booking;
use App\Services\AvailabilityService;
use App\Services\CheckInService;
use App\Support\Like;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Bookings of the provider's venues (read-only list with arrival check-in). Times are shown in the business timezone. */
class BookingResource extends Resource
{
    use ProviderResource;

    protected static ?string $model = Booking::class;

    protected static string $area = 'bookings';

    protected static ?string $viewPermission = 'orders.view';

    protected static ?string $managePermission = null;

    protected static string $langKey = 'bookings';

    protected static ?string $slug = 'bookings';

    protected static string|\UnitEnum|null $navigationGroup = ProviderNavGroup::Bookings;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getGloballySearchableAttributes(): array
    {
        return ['code'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Booking $record */
        return [
            __('provider.fields.customer') => $record->order?->customerDisplayName(),
            __('provider.fields.time') => static::window($record),
            __('provider.fields.status') => $record->status?->getLabel(),
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return static::getUrl('index', ['tableSearch' => $record->code]);
    }

    public static function canCheckIn(): bool
    {
        return ProviderAccess::canManage('checkin', 'bookings.checkin');
    }

    /** "2026-10-06 20:00 - 22:00" in the business timezone. */
    public static function window(Booking $booking): string
    {
        $tz = AvailabilityService::timezone();

        // Wrapped in left-to-right isolate marks: without them Arabic text shows it as "20:00 - 18:00 2026-10-10".
        return "\u{2066}".$booking->starts_at->timezone($tz)->format('Y-m-d H:i').' - '.$booking->ends_at->timezone($tz)->format('H:i')."\u{2069}";
    }

    public static function checkInAction(): Action
    {
        return Action::make('check_in')->label(__('provider.bookings.check_in'))->icon('heroicon-o-check-badge')->color('success')
            ->visible(fn (Booking $record) => static::canCheckIn() && $record->status === BookingStatus::Confirmed && $record->ends_at->isFuture())
            ->requiresConfirmation()
            ->modalDescription(fn (Booking $record) => __('provider.bookings.check_in_hint', [
                'customer' => $record->order?->customerDisplayName() ?? '-', 'time' => static::window($record),
            ]))
            ->action(fn (Booking $record) => Act::run(
                fn () => app(CheckInService::class)->checkIn(ProviderAccess::provider(), $record->code, null, auth()->user()),
                __('provider.checkin.success'),
            ));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('code')->label(__('provider.fields.code'))->fontFamily('mono')->weight('bold')->copyable(),
            TextEntry::make('status')->label(__('provider.fields.status'))->badge(),
            TextEntry::make('order.number')->label(__('provider.fields.order'))
                ->url(fn (Booking $r) => OrderResource::canViewAny() ? OrderResource::getUrl('view', ['record' => $r->order_id]) : null),
            TextEntry::make('venue.name_ar')->formatStateUsing(Fmt::localized('venue.name'))->label(__('provider.fields.venue')),
            TextEntry::make('activity.name_ar')->formatStateUsing(Fmt::localized('activity.name'))->label(__('provider.fields.activity')),
            TextEntry::make('resource.name_ar')->formatStateUsing(Fmt::localized('resource.name'))->label(__('provider.fields.resource')),
            TextEntry::make('time')->label(__('provider.fields.time'))->state(fn (Booking $r) => static::window($r))
                ->helperText(fn (Booking $r) => __('provider.bookings.slots_line', ['slots' => $r->slots, 'minutes' => $r->slot_minutes])),
            TextEntry::make('customer')->label(__('provider.fields.customer'))->state(fn (Booking $r) => $r->order?->customerDisplayName())
                ->helperText(fn (Booking $r) => Fmt::phone($r->order?->user?->phone ?? $r->order?->customer_phone)),
            TextEntry::make('total')->label(__('provider.fields.total'))->formatStateUsing(fn ($state) => Fmt::sar($state))
                ->helperText(fn (Booking $r) => __('provider.fields.unit_price').': '.Fmt::sar($r->unit_price)),
            TextEntry::make('checked_in_at')->label(__('provider.fields.checked_in_at'))
                ->dateTime('Y-m-d H:i', timezone: AvailabilityService::timezone())->placeholder('-')
                ->helperText(fn (Booking $r) => $r->checkedInBy?->name),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = AvailabilityService::timezone();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['venue', 'activity', 'resource', 'order.user']))
            ->defaultSort('starts_at', 'desc')
            ->emptyStateIcon('heroicon-o-clipboard-document-check')
            ->emptyStateHeading(__('provider.bookings.empty'))
            ->emptyStateDescription(__('provider.bookings.empty_hint'))
            ->columns([
                TextColumn::make('code')->label(__('provider.fields.code'))->searchable()->fontFamily('mono')->weight('bold')->copyable(),
                TextColumn::make('venue.name_ar')->visible(fn () => ProviderAccess::manyStores())->formatStateUsing(Fmt::localized('venue.name'))->label(__('provider.fields.venue'))->limit(25)->toggleable(),
                TextColumn::make('activity.name_ar')->formatStateUsing(Fmt::localized('activity.name'))->label(__('provider.fields.activity'))
                    ->description(fn (Booking $r) => Fmt::tr($r->resource, 'name')),
                TextColumn::make('customer')->label(__('provider.fields.customer'))
                    ->state(fn (Booking $r) => $r->order?->customerDisplayName())
                    ->description(fn (Booking $r) => Fmt::phone($r->order?->user?->phone ?? $r->order?->customer_phone))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('order', fn ($o) => $o
                        ->where('customer_name', 'like', Like::contains($search))
                        ->orWhere('customer_phone', 'like', Like::contains($search))
                        ->orWhere('number', 'like', Like::contains($search))
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', Like::contains($search))->orWhere('phone', 'like', Like::contains($search))))),
                TextColumn::make('starts_at')->label(__('provider.fields.time'))->sortable()
                    ->formatStateUsing(fn (Booking $record) => static::window($record))
                    ->description(fn (Booking $r) => __('provider.bookings.slots_line', ['slots' => $r->slots, 'minutes' => $r->slot_minutes])),
                TextColumn::make('status')->label(__('provider.fields.status'))->badge(),
                TextColumn::make('total')->label(__('provider.fields.total'))->formatStateUsing(fn ($state) => Fmt::sar($state))->sortable(),
            ])
            ->filters([
                SelectFilter::make('scope')->label(__('provider.fields.period'))
                    ->options([
                        'today' => __('provider.bookings.today'),
                        'upcoming' => __('provider.bookings.upcoming'),
                        'past' => __('provider.bookings.past'),
                    ])
                    ->query(function (Builder $query, array $data) use ($tz) {
                        $today = CarbonImmutable::now($tz)->startOfDay();

                        return match ($data['value'] ?? null) {
                            'today' => $query->whereBetween('starts_at', [$today->utc(), $today->endOfDay()->utc()]),
                            'upcoming' => $query->where('ends_at', '>=', now()),
                            'past' => $query->where('ends_at', '<', now()),
                            default => $query,
                        };
                    }),
                SelectFilter::make('status')->label(__('provider.fields.status'))->options(BookingStatus::class)->multiple(),
                SelectFilter::make('venue_id')->visible(fn () => ProviderAccess::manyStores())->label(__('provider.fields.venue'))
                    ->options(fn () => ProviderAccess::provider()?->venues()->orderBy('name_ar')->pluck('name_ar', 'id') ?? []),
            ])
            ->filtersFormColumns(3)
            ->recordActions([static::checkInAction(), ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListBookings::route('/')];
    }
}
