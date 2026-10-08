<?php

namespace App\Filament\Resources\Refunds\Pages;

use App\Enums\RefundStatus;
use App\Filament\Resources\Refunds\RefundResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListRefunds extends ListRecords
{
    protected static string $resource = RefundResource::class;

    public function getTabs(): array
    {
        return [
            'requested' => Tab::make(RefundStatus::Requested->getLabel())
                ->modifyQueryUsing(fn ($query) => $query->where('status', RefundStatus::Requested))
                ->badge(fn () => RefundResource::getNavigationBadge())
                ->badgeColor('warning'),
            'all' => Tab::make(__('admin.tabs.all')),
        ];
    }
}
