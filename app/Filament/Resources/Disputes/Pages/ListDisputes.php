<?php

namespace App\Filament\Resources\Disputes\Pages;

use App\Filament\Resources\Disputes\DisputeResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListDisputes extends ListRecords
{
    protected static string $resource = DisputeResource::class;

    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('admin.disputes.open'))
                ->modifyQueryUsing(fn ($query) => $query->whereIn('status', DisputeResource::OPEN_STATUSES))
                ->badge(fn () => DisputeResource::getNavigationBadge())
                ->badgeColor('warning'),
            'mine' => Tab::make(__('admin.disputes.mine'))
                ->modifyQueryUsing(fn ($query) => $query->where('assigned_to', auth()->id())->whereIn('status', DisputeResource::OPEN_STATUSES)),
            'all' => Tab::make(__('admin.tabs.all')),
        ];
    }
}
