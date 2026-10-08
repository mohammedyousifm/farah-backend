<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\PermissionRegistrar;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array<int, string> */
    protected array $permissionNames = [];

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->hidden(fn () => $this->getRecord()->users()->exists())];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + RoleResource::permissionState($this->getRecord()->load('permissions'));
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->permissionNames = RoleResource::extractPermissions($data);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->getRecord()->syncPermissions($this->permissionNames);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
