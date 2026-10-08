<?php

namespace App\Filament\Resources\CommissionRules\Pages;

use App\Filament\Resources\CommissionRules\CommissionRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCommissionRules extends ManageRecords
{
    protected static string $resource = CommissionRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(function (array $data): array {
                $data['scope_id'] = ($data['scope'] ?? 'global') === 'global' ? null : ($data['scope_id'] ?? null);
                $data['rate'] = (float) ($data['rate'] ?? 0);
                $data['fixed_amount'] = (float) ($data['fixed_amount'] ?? 0);
                $data['version'] = 1;
                $data['is_active'] = true;
                $data['created_by'] = auth()->id();

                return $data;
            }),
        ];
    }
}
