<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ProviderPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ProviderPanelProvider::class,
];
