<?php

namespace App\Filament\Provider\Resources\Ledger\Pages;

use App\Filament\Provider\Resources\Ledger\LedgerEntryResource;
use App\Filament\Provider\Widgets\BalancesWidget;
use Filament\Resources\Pages\ListRecords;

class ListLedgerEntries extends ListRecords
{
    protected static string $resource = LedgerEntryResource::class;

    public function getTitle(): string
    {
        return __('provider.finance.title');
    }

    protected function getHeaderWidgets(): array
    {
        return [BalancesWidget::class];
    }
}
