<?php

namespace App\Filament\Resources\PushCampaigns\Pages;

use App\Filament\Resources\PushCampaigns\PushCampaignResource;
use App\Models\PushCampaign;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPushCampaign extends EditRecord
{
    protected static string $resource = PushCampaignResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // A sent campaign is history: no more edits.
        abort_if($this->getRecord()->status === 'sent', 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            PushCampaignResource::sendAction()->record(fn () => $this->getRecord())
                ->after(fn () => $this->redirect(PushCampaignResource::getUrl('index'))),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var PushCampaign $record */
        $record = $this->getRecord();
        if ($record->status !== 'sent') {
            $data['status'] = filled($data['scheduled_at'] ?? null) ? 'scheduled' : 'draft';
        }
        $data['link_value'] ??= null;

        return $data;
    }
}
