<?php

namespace App\Filament\Provider\Resources\Venues\Pages;

use App\Filament\Provider\Resources\Venues\VenueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVenues extends ListRecords
{
    protected static string $resource = VenueResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('provider.venues.create'))];
    }
}
