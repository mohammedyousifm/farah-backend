<?php

namespace App\Filament\Resources\ProviderDocuments\Pages;

use App\Enums\DocumentStatus;
use App\Filament\Resources\ProviderDocuments\ProviderDocumentResource;
use App\Models\Setting;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ManageProviderDocuments extends ManageRecords
{
    protected static string $resource = ProviderDocumentResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make(DocumentStatus::Pending->getLabel())
                ->modifyQueryUsing(fn ($query) => $query->where('status', DocumentStatus::Pending))
                ->badge(fn () => ProviderDocumentResource::getNavigationBadge())
                ->badgeColor('warning'),
            'expiring' => Tab::make(__('admin.documents.expiring_soon'))
                ->modifyQueryUsing(fn ($query) => $query->where('status', DocumentStatus::Approved)->whereBetween('expires_at', [
                    now()->toDateString(),
                    now()->addDays((int) Setting::get('document_expiry_warning_days'))->toDateString(),
                ])),
            'all' => Tab::make(__('admin.tabs.all')),
        ];
    }
}
