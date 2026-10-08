<?php

namespace App\Filament\Resources\RecommendationRules\Pages;

use App\Filament\Resources\RecommendationRules\RecommendationRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRecommendationRules extends ManageRecords
{
    protected static string $resource = RecommendationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
