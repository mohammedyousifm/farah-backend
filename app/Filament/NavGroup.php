<?php

namespace App\Filament;

use Filament\Support\Contracts\HasLabel;

/** Sidebar groups, in display order. Labels come from lang/{ar,en}/admin.php. */
enum NavGroup implements HasLabel
{
    case Home;
    case Approvals;
    case Catalog;
    case Finance;
    case Support;
    case Content;
    case Reports;
    case System;

    public function getLabel(): string
    {
        return __('admin.nav.'.strtolower($this->name));
    }
}
