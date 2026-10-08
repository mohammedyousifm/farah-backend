<?php

namespace App\Filament\Resources\PushCampaigns\Pages;

use App\Filament\Resources\PushCampaigns\PushCampaignResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePushCampaign extends CreateRecord
{
    protected static string $resource = PushCampaignResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Workflow flag only: sending itself (sent / sent_at / recipients) is done by CampaignService.
        $data['status'] = filled($data['scheduled_at'] ?? null) ? 'scheduled' : 'draft';
        $data['created_by'] = auth()->id();
        $data['link_value'] ??= null;

        return $data;
    }
}
