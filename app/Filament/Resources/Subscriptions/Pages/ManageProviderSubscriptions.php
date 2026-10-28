<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\ProviderSubscriptionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageProviderSubscriptions extends ManageRecords
{
    protected static string $resource = ProviderSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [ProviderSubscriptionResource::assignAction()];
    }
}
