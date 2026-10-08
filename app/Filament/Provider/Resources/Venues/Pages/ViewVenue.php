<?php

namespace App\Filament\Provider\Resources\Venues\Pages;

use App\Filament\Provider\Resources\Venues\VenueResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVenue extends ViewRecord
{
    protected static string $resource = VenueResource::class;

    protected function getHeaderActions(): array
    {
        return [VenueResource::submitAction(), VenueResource::scheduleAction(), VenueResource::hoursAction(), EditAction::make()];
    }
}
