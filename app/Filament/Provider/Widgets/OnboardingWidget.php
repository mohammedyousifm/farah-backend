<?php

namespace App\Filament\Provider\Widgets;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\ProviderStatus;
use App\Enums\RequestStatus;
use App\Enums\VenueStatus;
use App\Filament\Provider\Pages\EditProviderProfile;
use App\Filament\Provider\Pages\Sectors;
use App\Filament\Provider\Resources\Documents\DocumentResource;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Models\Provider;
use App\Models\Venue;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Checklist shown on the dashboard.
 *  - Provider pending / rejected: what is still missing before the Farah team can approve.
 *  - Provider approved without a live venue yet: add a venue, set its hours, add
 *    activities and resources, submit it for review.
 */
class OnboardingWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.provider.onboarding';

    /** Documents we expect for each provider type (others are optional). */
    public static function requiredDocuments(Provider $provider): array
    {
        return [DocumentType::CommercialRegister, DocumentType::IbanLetter];
    }

    /** Profile fields still empty, as translated labels. */
    public static function missingProfileFields(Provider $provider): array
    {
        $fields = [
            'name_ar' => 'name_ar',
            'city_id' => 'city',
            'phone' => 'phone',
            'bank_name' => 'bank_name',
            'account_holder' => 'account_holder',
            'iban' => 'iban',
            'cr_number' => 'cr_number',
            'maps_url' => 'maps_url',
        ];

        return collect($fields)
            ->filter(fn (string $label, string $attribute) => blank($provider->getAttribute($attribute)))
            ->map(fn (string $label) => __("provider.fields.{$label}"))
            ->values()->all();
    }

    /** @return array<int, array{label: string, done: bool}> one row per required document */
    public static function documentChecklist(Provider $provider, Collection $documents): array
    {
        return collect(static::requiredDocuments($provider))->map(function (DocumentType $type) use ($documents) {
            $latest = $documents->where('type', $type)->sortByDesc('id')->first();
            $status = $latest?->status;

            return [
                'label' => $type->getLabel(),
                'status' => $status?->getLabel() ?? __('provider.onboarding.not_uploaded'),
                'state' => match ($status) {
                    DocumentStatus::Approved => 'done',
                    DocumentStatus::Pending => 'waiting',
                    null => 'todo',
                    default => 'problem',
                },
            ];
        })->all();
    }

    /** Approved provider that has no published venue yet: show the venue set-up steps. */
    public static function needsVenueSetup(?Provider $provider): bool
    {
        return $provider !== null
            && $provider->isApproved()
            && ! $provider->venues()->where('status', VenueStatus::Published)->exists();
    }

    /**
     * Steps to get the first venue live (the newest venue is the one being set up).
     *
     * @return array<int, array{key: string, label: string, done: bool, detail: string, url: ?string}>
     */
    public static function venueSteps(Provider $provider): array
    {
        /** @var Venue|null $venue */
        $venue = $provider->venues()->latest('id')->first();
        $canManage = ProviderAccess::canManage('venues', 'venues.manage');
        $venueUrl = $venue && $canManage ? VenueResource::getUrl('view', ['record' => $venue]) : null;

        $openDays = $venue ? $venue->hours()->where('is_closed', false)->count() : 0;
        $activities = $venue ? $venue->activities()->where('is_active', true)->count() : 0;
        $resources = $venue ? $venue->resources()->where('activities.is_active', true)->where('resources.is_active', true)->count() : 0;
        $submitted = $venue && in_array($venue->status, [VenueStatus::PendingReview, VenueStatus::Published], true);

        return [
            [
                'key' => 'venue',
                'label' => __('provider.onboarding.step_venue'),
                'done' => $venue !== null,
                'detail' => $venue ? VenueResource::title($venue) : __('provider.onboarding.venue_todo'),
                'url' => $venue ? $venueUrl : ($canManage ? VenueResource::getUrl('create') : null),
            ],
            [
                'key' => 'hours',
                'label' => __('provider.onboarding.step_hours'),
                'done' => $openDays > 0,
                'detail' => $openDays > 0
                    ? trans_choice('provider.onboarding.hours_count', $openDays, ['count' => $openDays])
                    : __('provider.onboarding.hours_todo'),
                'url' => $venueUrl,
            ],
            [
                'key' => 'activities',
                'label' => __('provider.onboarding.step_activities'),
                'done' => $activities > 0 && $resources > 0,
                'detail' => $activities > 0 && $resources > 0
                    ? __('provider.onboarding.activities_count', ['activities' => $activities, 'resources' => $resources])
                    : ($activities > 0 ? __('provider.onboarding.resources_todo') : __('provider.onboarding.activities_todo')),
                'url' => $venueUrl,
            ],
            [
                'key' => 'submit',
                'label' => __('provider.onboarding.step_submit'),
                'done' => $submitted,
                'detail' => match (true) {
                    $submitted => __('provider.onboarding.submit_pending'),
                    $venue?->status === VenueStatus::Rejected => __('provider.onboarding.submit_rejected'),
                    default => __('provider.onboarding.submit_todo'),
                },
                'url' => $venueUrl,
            ],
        ];
    }

    protected function getViewData(): array
    {
        $provider = ProviderAccess::provider();

        if ($provider->isApproved()) {
            $steps = static::venueSteps($provider);
            $completed = collect($steps)->where('done', true)->count();

            return [
                'provider' => $provider,
                'title' => __('provider.onboarding.venue_title'),
                'description' => __('provider.onboarding.venue_description'),
                'readyLabel' => __('provider.onboarding.venue_ready'),
                'steps' => $steps,
                'progress' => (int) round($completed / count($steps) * 100),
                'readyForReview' => $completed === count($steps),
                'rejectedDocuments' => 0,
            ];
        }

        $documents = $provider->documents()->get();
        $canManage = ProviderAccess::allows('profile.manage');

        $missing = static::missingProfileFields($provider);
        $docRows = static::documentChecklist($provider, $documents);
        $docsDone = collect($docRows)->every(fn ($r) => in_array($r['state'], ['done', 'waiting'], true));
        $sectorCount = $provider->sectors()->count();
        $pendingSectorRequests = $provider->sectorRequests()->where('status', RequestStatus::Pending)->count();

        $steps = [
            [
                'key' => 'profile',
                'label' => __('provider.onboarding.step_profile'),
                'done' => $missing === [],
                'detail' => $missing === [] ? __('provider.onboarding.profile_ok') : __('provider.onboarding.missing', ['fields' => implode('، ', $missing)]),
                'url' => $canManage ? EditProviderProfile::getUrl() : null,
            ],
            [
                'key' => 'documents',
                'label' => __('provider.onboarding.step_documents'),
                'done' => $docsDone,
                'items' => $docRows,
                'url' => $canManage ? DocumentResource::getUrl() : null,
            ],
            [
                'key' => 'sectors',
                'label' => __('provider.onboarding.step_sectors'),
                'done' => $sectorCount > 0 || $pendingSectorRequests > 0,
                'detail' => $sectorCount > 0
                    ? trans_choice('provider.onboarding.sectors_count', $sectorCount, ['count' => $sectorCount])
                    : ($pendingSectorRequests > 0 ? __('provider.onboarding.sector_request_pending') : __('provider.onboarding.no_sectors')),
                'url' => Sectors::getUrl(),
            ],
            [
                'key' => 'review',
                'label' => __('provider.onboarding.step_review'),
                'done' => false,
                'detail' => $provider->status === ProviderStatus::Rejected
                    ? __('provider.onboarding.review_rejected')
                    : __('provider.onboarding.review_pending'),
                'url' => null,
            ],
        ];

        $completed = collect($steps)->where('done', true)->count();

        return [
            'provider' => $provider,
            'title' => __('provider.onboarding.title'),
            'description' => __('provider.onboarding.description'),
            'readyLabel' => __('provider.onboarding.ready'),
            'steps' => $steps,
            'progress' => (int) round($completed / (count($steps) - 1) * 100),
            'readyForReview' => $completed >= count($steps) - 1,
            'rejectedDocuments' => $documents->where('status', DocumentStatus::Rejected)->count(),
        ];
    }
}
