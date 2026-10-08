<?php

namespace App\Filament\Provider\Resources\SectorRequests\Pages;

use App\Exceptions\BusinessException;
use App\Filament\Provider\Resources\SectorRequests\SectorRequestResource;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Exceptions\Halt;

class ManageSectorRequests extends ManageRecords
{
    protected static string $resource = SectorRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('provider.sector_requests.create'))
                ->successNotificationTitle(__('provider.sector_requests.created'))
                ->using(function (array $data) {
                    try {
                        return SectorRequestResource::store($data);
                    } catch (BusinessException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        throw new Halt;
                    }
                }),
        ];
    }
}
