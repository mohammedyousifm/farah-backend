<?php

namespace App\Filament\Resources\Venues\Pages;

use App\Enums\VenueStatus;
use App\Filament\Resources\Venues\VenueResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListVenues extends ListRecords
{
    protected static string $resource = VenueResource::class;

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('admin.tabs.all'))];

        foreach (VenueStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->getLabel())
                ->modifyQueryUsing(fn ($query) => $query->where('status', $status))
                ->badge(fn () => $status === VenueStatus::PendingReview ? (VenueResource::getNavigationBadge() ?: null) : null)
                ->badgeColor('warning');
        }

        return $tabs;
    }
}
