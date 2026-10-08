<?php

namespace App\Filament\Resources\Complaints\Pages;

use App\Enums\ComplaintStatus;
use App\Filament\Resources\Complaints\ComplaintResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListComplaints extends ListRecords
{
    protected static string $resource = ComplaintResource::class;

    public function getTabs(): array
    {
        return [
            'active' => Tab::make(__('admin.complaints.active'))
                ->modifyQueryUsing(fn ($query) => $query->whereIn('status', [ComplaintStatus::Open, ComplaintStatus::InProgress]))
                ->badge(fn () => ComplaintResource::getNavigationBadge())
                ->badgeColor('danger'),
            'mine' => Tab::make(__('admin.complaints.assigned_to_me'))
                ->modifyQueryUsing(fn ($query) => $query->where('assigned_to', auth()->id())),
            'all' => Tab::make(__('admin.tabs.all')),
        ];
    }
}
