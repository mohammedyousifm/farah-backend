<?php

namespace App\Filament\Provider\Widgets;

use App\Enums\BookingStatus;
use App\Filament\Provider\Resources\Bookings\BookingResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Fmt;
use App\Models\Booking;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Who is playing now and who arrives next (today first). */
class UpcomingBookingsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $provider = ProviderAccess::provider();

        return $table
            ->heading(__('provider.dashboard.upcoming_bookings'))
            ->query(Booking::query()
                ->where('provider_id', $provider?->id)
                ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn])
                ->where('ends_at', '>=', now())
                ->with(['venue', 'activity', 'resource', 'order.user'])
                ->orderBy('starts_at'))
            ->paginated([8])
            ->defaultPaginationPageOption(8)
            ->emptyStateHeading(__('provider.dashboard.no_upcoming'))
            ->columns([
                TextColumn::make('starts_at')->label(__('provider.fields.time'))->weight('bold')
                    ->formatStateUsing(fn (Booking $record) => BookingResource::window($record)),
                TextColumn::make('activity.name_ar')->formatStateUsing(Fmt::localized('activity.name'))->label(__('provider.fields.activity'))
                    ->description(fn (Booking $r) => Fmt::tr($r->resource, 'name')),
                TextColumn::make('venue.name_ar')->formatStateUsing(Fmt::localized('venue.name'))->label(__('provider.fields.venue'))->limit(30),
                TextColumn::make('customer')->label(__('provider.fields.customer'))->state(fn (Booking $r) => $r->order?->customerDisplayName()),
                TextColumn::make('code')->label(__('provider.fields.code'))->fontFamily('mono'),
                TextColumn::make('status')->label(__('provider.fields.status'))->badge(),
            ])
            ->recordActions([BookingResource::checkInAction()]);
    }

    public static function canView(): bool
    {
        return ProviderAccess::canView('bookings', 'orders.view');
    }
}
