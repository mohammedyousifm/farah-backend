<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Wallet\WalletTransactionResource;
use App\Models\User;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            WalletTransactionResource::grantAction(user: function (): User {
                /** @var User $user */
                $user = $this->getRecord();

                return $user;
            }),
            ...UserResource::statusActions(),
        ];
    }
}
