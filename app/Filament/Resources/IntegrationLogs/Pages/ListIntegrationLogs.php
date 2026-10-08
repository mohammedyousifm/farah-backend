<?php

namespace App\Filament\Resources\IntegrationLogs\Pages;

use App\Filament\Resources\IntegrationLogs\IntegrationLogResource;
use Filament\Resources\Pages\ListRecords;

class ListIntegrationLogs extends ListRecords
{
    protected static string $resource = IntegrationLogResource::class;
}
