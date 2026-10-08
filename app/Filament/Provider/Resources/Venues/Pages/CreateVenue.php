<?php

namespace App\Filament\Provider\Resources\Venues\Pages;

use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Services\VenueService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;

/** Creates the venue as a draft through VenueService, with its weekly working hours. */
class CreateVenue extends CreateRecord
{
    use HasWizard;

    protected static string $resource = VenueResource::class;

    /** Same sections as the edit tabs plus the working hours, one step each. */
    protected function getSteps(): array
    {
        return collect(VenueResource::formSections())->map(fn (array $section) => Step::make($section['label'])
            ->description($section['description'])
            ->icon($section['icon'])
            ->columns($section['columns'])
            ->schema($section['schema']))->values()->all();
    }

    protected function handleRecordCreation(array $data): Model
    {
        $hours = VenueResource::hoursRows((array) ($data['hours'] ?? []));
        unset($data['hours']);

        return VenueResource::runService(fn () => app(VenueService::class)->create(ProviderAccess::provider(), $data, $hours));
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('provider.venues.created');
    }

    protected function getRedirectUrl(): string
    {
        return VenueResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
