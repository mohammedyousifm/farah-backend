<?php

namespace App\Filament\Provider\Resources\Documents\Pages;

use App\Exceptions\BusinessException;
use App\Filament\Provider\Resources\Documents\DocumentResource;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Exceptions\Halt;

class ManageDocuments extends ManageRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('provider.documents.upload'))->icon('heroicon-o-arrow-up-tray')
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
