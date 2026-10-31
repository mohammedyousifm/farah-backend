<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Enums\BookingStatus;
use App\Http\Resources\BookingResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProviderProfileResource;
use App\Http\Resources\VenueCardResource;
use App\Models\Booking;
use App\Services\AvailabilityService;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Home screen of the provider app. */
class DashboardController extends ProviderController
{
    private const PAID = ['confirmed', 'completed', 'partially_refunded'];

    public function __invoke(Request $request, LedgerService $ledger): JsonResponse
    {
        $provider = $this->provider($request);
        $member = $this->member($request);
        $canFinance = $member->allows('finance.view');
        $canOrders = $member->allows('orders.view');

        $paid = fn () => $provider->orders()->whereIn('status', self::PAID);
        $venuesByStatus = $provider->venues()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        // "Today" and "this month" are calendar periods in the business timezone (the columns hold UTC).
        $local = CarbonImmutable::now(AvailabilityService::timezone());
        $dayStart = $local->startOfDay();
        $today = [$dayStart->utc(), $dayStart->addDay()->utc()];
        $monthStart = $local->startOfMonth()->utc();
        $live = [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed];
        $bookings = fn () => Booking::where('provider_id', $provider->id);

        return response()->json(['data' => [
            'provider' => (new ProviderProfileResource($provider->loadMissing('city')))->resolve($request),
            'membership' => [
                'role' => $member->role,
                'permissions' => in_array($member->role, ['owner', 'manager'], true) ? ['*'] : ($member->permissions ?? []),
            ],
            'venues' => [
                'by_status' => $venuesByStatus,
                'total' => (int) $venuesByStatus->sum(),
                'list' => VenueCardResource::collection(
                    $provider->venues()->with(VenueCardResource::RELATIONS)->latest()->limit(5)->get()
                )->resolve($request),
            ],
            'bookings' => [
                'today' => $bookings()->whereIn('status', $live)->where('starts_at', '>=', $today[0])->where('starts_at', '<', $today[1])->count(),
                'checked_in_today' => $bookings()->where('checked_in_at', '>=', $today[0])->where('checked_in_at', '<', $today[1])->count(),
                'this_month' => $bookings()->whereIn('status', $live)->whereHas('order', fn ($q) => $q->where('confirmed_at', '>=', $monthStart))->count(),
                // Who is expected next, for the counter (booking codes: staff who handle orders or arrivals only).
                'next' => $canOrders || $member->allows('bookings.checkin') ? BookingResource::collection(
                    $bookings()->where('status', BookingStatus::Confirmed)->where('ends_at', '>', now())
                        ->with(['activity', 'resource', 'venue.city', 'order'])->orderBy('starts_at')->limit(5)->get()
                )->resolve($request) : null,
            ],
            'orders' => $canOrders ? [
                'today' => $paid()->where('confirmed_at', '>=', $today[0])->where('confirmed_at', '<', $today[1])->count(),
                'this_month' => $paid()->where('confirmed_at', '>=', $monthStart)->count(),
                'recent' => OrderResource::collection($provider->orders()->with(['venue.city', 'bookings.activity', 'bookings.resource'])->latest()->limit(5)->get())->resolve($request),
            ] : null,
            'sales' => $canFinance ? [
                'this_month' => round((float) $paid()->where('confirmed_at', '>=', $monthStart)->sum('total'), 2),
                'net_this_month' => round((float) $paid()->where('confirmed_at', '>=', $monthStart)->sum('provider_net'), 2),
                'balances' => $ledger->balances($provider),
                'currency' => config('farah.currency'),
            ] : null,
            'reviews' => [
                'rating_avg' => $provider->rating_avg,
                'rating_count' => $provider->rating_count,
                'unreplied' => $provider->reviews()->where('status', 'published')->whereNull('provider_reply')->count(),
            ],
            'documents' => [
                'pending' => $provider->documents()->where('status', 'pending')->count(),
                'rejected' => $provider->documents()->where('status', 'rejected')->count(),
                'expired' => $provider->documents()->where('status', 'expired')->count(),
                // Asked for by the platform while reviewing and not sent yet.
                'requested' => $provider->documentRequests()->open()->get()
                    ->map(fn ($r) => ['id' => $r->id, 'type' => $r->type->value, 'label' => $r->label(), 'note' => $r->note])->values(),
            ],
        ]]);
    }
}
