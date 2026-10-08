<?php

namespace App\Filament\Provider\Resources\Venues\Pages;

use App\Enums\VenueStatus;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Services\VenueService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Edit through VenueService: a published / rejected venue goes back to review after saving. */
class EditVenue extends EditRecord
{
    protected static string $resource = VenueResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), VenueResource::hoursAction(), VenueResource::submitAction(), DeleteAction::make()];
    }

    public function getSubheading(): ?string
    {
        return in_array($this->getRecord()->status, [VenueStatus::Published, VenueStatus::Rejected], true)
            ? __('provider.venues.edit_goes_to_review')
            : null;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $oldCover = $record->cover_image;

        $venue = VenueResource::runService(fn () => app(VenueService::class)->update($record, $data));

        if ($oldCover && $oldCover !== $venue->cover_image && ! str_starts_with($oldCover, 'demo/')) {
            Storage::disk('public')->delete($oldCover);
        }

        return $venue;
    }

    protected function getRedirectUrl(): string
    {
        return VenueResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
