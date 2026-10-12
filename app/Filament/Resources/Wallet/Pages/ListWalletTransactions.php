<?php

namespace App\Filament\Resources\Wallet\Pages;

use App\Filament\Resources\Wallet\WalletTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListWalletTransactions extends ListRecords
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [WalletTransactionResource::grantAction()];
    }
}
