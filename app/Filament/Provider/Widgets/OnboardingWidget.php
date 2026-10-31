<?php

namespace App\Filament\Provider\Widgets;

use App\Enums\DocumentStatus;
use App\Enums\ProviderStatus;
use App\Enums\RequestStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\VenueStatus;
use App\Filament\Provider\Pages\EditProviderProfile;
use App\Filament\Provider\Pages\MyPlan;
use App\Filament\Provider\Pages\Sectors;
use App\Filament\Provider\Resources\Documents\DocumentResource;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Models\Provider;
use App\Models\ProviderDocumentRequest;
use App\Models\Venue;
use Filament\Widgets\Widget;

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

    /** Open document requests: something the platform asked for that has not arrived (or was rejected). */
    public static function hasOpenRequests(?Provider $provider): bool
    {
        return $provider !== null && $provider->documentRequests()->open()->exists();
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

    /**
     * No document is asked of every store: only what the platform requested while reviewing it. One row per request
     * still in play (not sent yet, waiting for review, or rejected); approved answers drop off.
     *
     * @return array<int, array{label: string, status: string, state: string}>
     */
    public static function documentChecklist(Provider $provider): array
    {
        return $provider->documentRequests()->with('document')->latest('id')->get()
            ->reject(fn (ProviderDocumentRequest $r) => $r->document?->status === DocumentStatus::Approved)
            ->map(fn (ProviderDocumentRequest $r) => [
                'label' => $r->label(),
                'status' => $r->document?->status->getLabel() ?? __('provider.onboarding.not_uploaded'),
                'state' => match ($r->document?->status) {
                    DocumentStatus::Pending => 'waiting',
                    null => 'todo',
                    default => 'problem',
                },
            ])->values()->all();
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

        $requested = static::documentChecklist($provider);
        $requestsUrl = ProviderAccess::allows('profile.manage') ? DocumentResource::getUrl() : null;

        if ($provider->isApproved()) {
            $steps = static::needsVenueSetup($provider) ? static::venueSteps($provider) : [];
            $completed = collect($steps)->where('done', true)->count();

            return [
                'provider' => $provider,
                'title' => __('provider.onboarding.venue_title'),
                'description' => __('provider.onboarding.venue_description'),
                'readyLabel' => __('provider.onboarding.venue_ready'),
                'steps' => $steps,
                'progress' => $steps ? (int) round($completed / count($steps) * 100) : 100,
                'readyForReview' => $completed === count($steps),
                'rejectedDocuments' => 0,
                'requested' => $requested,
                'requestsUrl' => $requestsUrl,
            ];
        }

        $documents = $provider->documents()->get();
        $canManage = ProviderAccess::allows('profile.manage');

        $missing = static::missingProfileFields($provider);
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
            // Only when the platform asked for something while reviewing (nothing is required up front).
            ...($requested === [] ? [] : [[
                'key' => 'documents',
                'label' => __('provider.onboarding.step_documents'),
                'done' => collect($requested)->every(fn ($r) => $r['state'] === 'waiting'),
                'items' => $requested,
                'url' => $requestsUrl,
            ]]),
            [
                'key' => 'sectors',
                'label' => __('provider.onboarding.step_sectors'),
                'done' => $sectorCount > 0 || $pendingSectorRequests > 0,
                'detail' => $sectorCount > 0
                    ? trans_choice('provider.onboarding.sectors_count', $sectorCount, ['count' => $sectorCount])
                    : ($pendingSectorRequests > 0 ? __('provider.onboarding.sector_request_pending') : __('provider.onboarding.no_sectors')),
                'url' => Sectors::getUrl(),
            ],
            // Plans from day one: everyone starts on the free plan, so this step is never in the way.
            [
                'key' => 'plan',
                'label' => __('provider.onboarding.step_plan'),
                'done' => true,
                'detail' => ($pendingPlan = $provider->subscriptions()->where('status', SubscriptionStatus::Pending)->with('plan')->first())
                    ? __('provider.onboarding.plan_pending', ['plan' => $pendingPlan->plan->tr('name')])
                    : __('provider.onboarding.plan_free'),
                'url' => MyPlan::canAccess() ? MyPlan::getUrl() : null,
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

        // The plan step (always done) and the platform's review do not count towards the progress.
        $required = collect($steps)->whereNotIn('key', ['plan', 'review']);
        $completed = $required->where('done', true)->count();

        return [
            'provider' => $provider,
            'title' => __('provider.onboarding.title'),
            'description' => __('provider.onboarding.description'),
            'readyLabel' => __('provider.onboarding.ready'),
            'steps' => $steps,
            'progress' => (int) round($completed / $required->count() * 100),
            'readyForReview' => $completed === $required->count(),
            'rejectedDocuments' => $documents->where('status', DocumentStatus::Rejected)->count(),
            'requested' => [],
            'requestsUrl' => null,
        ];
    }
}
