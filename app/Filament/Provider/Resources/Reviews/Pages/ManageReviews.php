<?php

namespace App\Filament\Provider\Resources\Reviews\Pages;

use App\Filament\Provider\Resources\Reviews\ReviewResource;
use Filament\Resources\Pages\ManageRecords;

class ManageReviews extends ManageRecords
{
    protected static string $resource = ReviewResource::class;
}
