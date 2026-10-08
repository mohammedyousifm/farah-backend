<?php

namespace App\Filament\Resources\Providers\Pages;

use App\Enums\ProviderStatus;
use App\Filament\Resources\Providers\ProviderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListProviders extends ListRecords
{
    protected static string $resource = ProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('admin.tabs.all'))];

        foreach (ProviderStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->getLabel())
                ->modifyQueryUsing(fn ($query) => $query->where('status', $status))
                ->badge(fn () => $status === ProviderStatus::Pending ? (ProviderResource::getNavigationBadge() ?: null) : null)
                ->badgeColor('warning');
        }

        return $tabs;
    }
}
