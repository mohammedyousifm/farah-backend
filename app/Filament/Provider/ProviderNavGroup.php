<?php

namespace App\Filament\Provider;

use Filament\Support\Contracts\HasLabel;

/** Provider panel sidebar groups, in display order. Labels: lang/{ar,en}/provider.php → nav. */
enum ProviderNavGroup implements HasLabel
{
    case Venues;
    case Bookings;
    case Finance;
    case Account;

    public function getLabel(): string
    {
        return __('provider.nav.'.strtolower($this->name));
    }
}
