<?php

namespace App\Filament\Provider\Resources\Orders\Pages;

use App\Filament\Provider\Pages\Schedule;
use App\Filament\Provider\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [static::counterBookingAction()];
    }

    /** A booking made at the counter (cash / transfer / POS) starts from a free slot in the schedule. */
    public static function counterBookingAction(): Action
    {
        return Action::make('counter_booking')->label(__('provider.orders.offline'))->icon('heroicon-o-banknotes')
            ->visible(fn () => Schedule::canAccess() && Schedule::canBook())
            ->tooltip(__('provider.orders.offline_hint'))
            ->url(fn () => Schedule::getUrl());
    }
}
