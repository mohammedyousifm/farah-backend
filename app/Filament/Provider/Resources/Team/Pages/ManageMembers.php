<?php

namespace App\Filament\Provider\Resources\Team\Pages;

use App\Filament\Provider\Resources\Team\MemberResource;
use App\Filament\Support\Act;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;

class ManageMembers extends ManageRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')->label(__('provider.team.invite'))->icon('heroicon-o-user-plus')
                ->visible(fn () => MemberResource::canManage())
                ->modalWidth('2xl')
                ->schema(MemberResource::inviteSchema())
                ->action(fn (array $data) => Act::run(fn () => MemberResource::invite($data), __('api.providers.member_added'))),
        ];
    }
}
