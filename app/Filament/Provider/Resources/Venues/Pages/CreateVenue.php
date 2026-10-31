<?php

namespace App\Filament\Provider\Resources\Venues\Pages;

use App\Filament\Concerns\KeepsDraft;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Services\VenueService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

/** Creates the venue as a draft through VenueService, with its weekly working hours. */
class CreateVenue extends CreateRecord
{
    use HasWizard {
        getWizardComponent as wizardComponent;
    }
    use KeepsDraft;

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

    /** A refresh stays on the step the owner was on. */
    public function getWizardComponent(): Component
    {
        /** @var Wizard $wizard */
        $wizard = $this->wizardComponent();

        return $wizard->persistStepInQueryString();
    }

    protected function fillForm(): void
    {
        parent::fillForm();
        $this->restoreDraft();
    }

    protected function draftExcept(): array
    {
        return ['cover_image'];
    }

    public function getFooter(): ?View
    {
        return view('filament.keep-draft');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $hours = VenueResource::hoursRows((array) ($data['hours'] ?? []));
        unset($data['hours']);

        return VenueResource::runService(fn () => app(VenueService::class)->create(ProviderAccess::provider(), $data, $hours));
    }

    protected function afterCreate(): void
    {
        $this->forgetDraft();
    }

    /**
     * One store per provider: once this page has created it, "add" is closed. A request still on its way from the
     * same page (a second click on "create", the redirect racing a blur) must not end on a 403 screen.
     */
    protected function authorizeAccess(): void
    {
        abort_unless($this->record !== null || static::getResource()::canCreate(), 403);
    }

    public function create(bool $another = false): void
    {
        if ($this->record !== null) {
            $this->redirect($this->getRedirectUrl(), navigate: FilamentView::hasSpaMode($this->getRedirectUrl()));

            return;
        }

        parent::create($another);
    }

    /** Leaving a field after the store exists sends nothing more (it would only re-save the draft). */
    public function updatedData(): void
    {
        if ($this->record === null) {
            $this->saveDraft();
        }
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
