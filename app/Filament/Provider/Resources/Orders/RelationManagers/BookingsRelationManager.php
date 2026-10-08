<?php

namespace App\Filament\Provider\Resources\Orders\RelationManagers;

use App\Filament\Provider\Concerns\ProviderRelationManager;
use App\Filament\Provider\Resources\Bookings\BookingResource;
use App\Filament\Support\Fmt;
use App\Models\Booking;
use App\Services\AvailabilityService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** What the order reserved: one row per resource and run of slots, with its arrival code. */
class BookingsRelationManager extends RelationManager
{
    use ProviderRelationManager;

    protected static string $relationship = 'bookings';

    protected static string $area = 'orders';

    protected static ?string $managePermission = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('provider.orders.bookings');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['activity', 'resource', 'checkedInBy', 'order.user']))
            ->columns([
                TextColumn::make('code')->label(__('provider.fields.code'))->fontFamily('mono')->copyable()->searchable(),
                TextColumn::make('activity.name_ar')->formatStateUsing(Fmt::localized('activity.name'))->label(__('provider.fields.activity'))->weight('bold')
                    ->description(fn (Booking $r) => Fmt::tr($r->resource, 'name')),
                TextColumn::make('starts_at')->label(__('provider.fields.time'))
                    ->formatStateUsing(fn (Booking $record) => BookingResource::window($record))
                    ->description(fn (Booking $r) => __('provider.bookings.slots_line', ['slots' => $r->slots, 'minutes' => $r->slot_minutes])),
                TextColumn::make('unit_price')->label(__('provider.fields.unit_price'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextColumn::make('total')->label(__('provider.fields.total'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextColumn::make('status')->label(__('provider.fields.status'))->badge(),
                TextColumn::make('checked_in_at')->label(__('provider.fields.checked_in_at'))
                    ->dateTime('Y-m-d H:i', timezone: AvailabilityService::timezone())->placeholder('-')
                    ->description(fn (Booking $r) => $r->checkedInBy?->name),
            ])
            ->recordActions([BookingResource::checkInAction()]);
    }
}
