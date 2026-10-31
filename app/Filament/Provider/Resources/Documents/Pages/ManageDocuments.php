<?php

namespace App\Filament\Provider\Resources\Documents\Pages;

use App\Exceptions\BusinessException;
use App\Filament\Provider\Resources\Documents\DocumentResource;
use App\Filament\Provider\Support\ProviderAccess;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Exceptions\Halt;

class ManageDocuments extends ManageRecords
{
    protected static string $resource = DocumentResource::class;

    /** What the platform asked for (and is still waiting on), or that nothing is needed right now. */
    public function getSubheading(): ?string
    {
        $open = ProviderAccess::provider()?->documentRequests()->open()->get() ?? collect();

        return $open->isEmpty()
            ? __('provider.documents.optional_hint')
            : __('provider.documents.requested_title').': '.$open->map->label()->implode('، ');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('provider.documents.upload'))->icon('heroicon-o-arrow-up-tray')
                // Opens on the type that was asked for, when something was.
                ->fillForm(fn () => ['type' => ProviderAccess::provider()?->documentRequests()->open()->oldest('id')->value('type')])
                ->successNotificationTitle(__('provider.documents.uploaded'))
                ->using(function (array $data) {
                    try {
                        return DocumentResource::store($data);
                    } catch (BusinessException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        throw new Halt;
                    }
                }),
        ];
    }
}
