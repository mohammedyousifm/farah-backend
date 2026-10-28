<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Enums\VenueStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Provider;
use App\Models\ProviderMember;
use App\Models\Venue;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Base for provider-app endpoints: the acting provider is resolved by the `provider` middleware. */
abstract class ProviderController extends Controller
{
    protected function provider(Request $request): Provider
    {
        return $request->attributes->get('provider');
    }

    protected function member(Request $request): ProviderMember
    {
        return $request->attributes->get('provider_member');
    }

    /** A venue of the acting provider, or 404 (never leaks other providers' venues). */
    protected function venue(Request $request, int $id): Venue
    {
        return $this->provider($request)->venues()->findOrFail($id);
    }

    /** An activity of one of the acting provider's venues, or 404. */
    protected function activity(Request $request, int $venueId, int $id): Activity
    {
        return $this->venue($request, $venueId)->activities()->findOrFail($id);
    }

    /** A venue suspended by the platform is locked until the suspension is lifted. */
    protected function assertEditable(Venue $venue): void
    {
        if ($venue->status === VenueStatus::Suspended) {
            throw new BusinessException(__('api.venues.locked'), 'venue_locked');
        }
    }

    /** ?date_from / ?date_to are calendar days on the venues' clock; the column holds UTC. */
    protected function filterDays(Builder $query, ?string $from, ?string $to, string $column = 'created_at'): void
    {
        $timezone = AvailabilityService::timezone();

        if ($from) {
            $query->where($column, '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
        }
        if ($to) {
            $query->where($column, '<', CarbonImmutable::parse($to, $timezone)->startOfDay()->addDay()->utc());
        }
    }
}
