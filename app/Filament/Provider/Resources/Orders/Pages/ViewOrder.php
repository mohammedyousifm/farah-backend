<?php

namespace App\Filament\Provider\Resources\Orders\Pages;

use App\Filament\Provider\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;
}
