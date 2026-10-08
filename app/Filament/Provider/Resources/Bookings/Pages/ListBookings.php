<?php

namespace App\Filament\Provider\Resources\Bookings\Pages;

use App\Filament\Provider\Pages\CheckIn;
use App\Filament\Provider\Pages\Schedule;
use App\Filament\Provider\Resources\Bookings\BookingResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check_in_page')->label(__('provider.checkin.title'))->icon('heroicon-o-qr-code')->color('gray')
                ->visible(fn () => CheckIn::canAccess())
                ->url(fn () => CheckIn::getUrl()),
            Action::make('schedule')->label(__('provider.schedule.title'))->icon('heroicon-o-calendar-days')
                ->visible(fn () => Schedule::canAccess())
                ->url(fn () => Schedule::getUrl()),
        ];
    }
}
