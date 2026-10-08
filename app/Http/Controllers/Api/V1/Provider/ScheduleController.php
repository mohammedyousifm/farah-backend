<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Requests\Api\Customer\AvailabilityRequest;
use App\Http\Requests\Api\Provider\SlotBlockRequest;
use App\Models\BookingSlot;
use App\Models\Venue;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The day at a venue as the counter sees it: every slot of every resource with who
 * booked it, plus taking slots out of sale (maintenance, private use) and back.
 */
class ScheduleController extends ProviderController
{
    /**
     * GET ?date=YYYY-MM-DD (today when omitted) & activity_id (optional).
     * Slot status: available | booked | blocked | past. A slot that already started
     * can still be sold at the counter until it ends, so it stays "available".
     */
    public function show(AvailabilityRequest $request, int $venue, AvailabilityService $availability): JsonResponse
    {
        $model = $this->venue($request, $venue)->load(['hours', 'activities.resources']);
        $member = $this->member($request);
        $withCustomer = $member->allows('orders.view') || $member->allows('bookings.checkin');

        $timezone = AvailabilityService::timezone();
        $date = $request->input('date') ?: CarbonImmutable::now($timezone)->format('Y-m-d');
        $window = $availability->window($model, $date);
        $now = CarbonImmutable::now();

        $activities = $model->activities
            ->when($request->integer('activity_id'), fn ($c, $id) => $c->where('id', $id))
            ->values();

        $held = $window ? $this->heldSlots($model, $window) : collect();

        return response()->json(['data' => [
            'venue_id' => $model->id,
            'date' => $date,
            'timezone' => $timezone,
            'is_open' => $window !== null,
            'opens_at' => $window ? $window[0]->format('H:i') : null,
            'closes_at' => $window ? $window[1]->format('H:i') : null,
            'activities' => $activities->map(function ($activity) use ($model, $availability, $date, $held, $now, $timezone, $withCustomer) {
                $activity->setRelation('venue', $model);
                $day = $availability->forDate($activity, $date);

                return [
                    'id' => $activity->id,
                    'name' => $activity->tr('name'),
                    'is_active' => (bool) $activity->is_active,
                    'slot_minutes' => $activity->slot_minutes,
                    'slot_price' => $activity->slotPrice(),
                    'min_slots' => $activity->min_slots,
                    'max_slots' => $activity->max_slots,
                    'resources' => collect($day['resources'])->map(fn (array $row) => [
                        'id' => $row['resource']->id,
                        'name' => $row['resource']->tr('name'),
                        'slots' => array_map(function (array $slot) use ($row, $held, $now, $timezone, $withCustomer) {
                            $taken = $held->get($row['resource']->id.':'.$slot['starts_at']->getTimestamp());

                            return [
                                'starts_at' => $slot['starts_at']->toIso8601String(),
                                'ends_at' => $slot['ends_at']->toIso8601String(),
                                'time' => $slot['starts_at']->setTimezone($timezone)->format('H:i'),
                                'end_time' => $slot['ends_at']->setTimezone($timezone)->format('H:i'),
                                'status' => match (true) {
                                    $taken?->booking_id !== null => 'booked',
                                    $taken !== null => 'blocked',
                                    $slot['ends_at']->lessThanOrEqualTo($now) => 'past',
                                    default => 'available',
                                },
                                // Blocked slots: id to pass to DELETE .../blocks/{slot}.
                                'slot_id' => $taken && $taken->booking_id === null ? $taken->id : null,
                                'block_reason' => $taken && $taken->booking_id === null ? $taken->block_reason : null,
                                'booking' => $taken?->booking ? $this->booking($taken->booking, $withCustomer) : null,
                            ];
                        }, $row['slots']),
                    ])->values(),
                ];
            })->values(),
        ]]);
    }

    /** Body: {resource_id, starts_at, slots, reason?}. 409 slot_taken when any of the slots is booked or already blocked. */
    public function block(SlotBlockRequest $request, int $venue, AvailabilityService $availability): JsonResponse
    {
        $model = $this->venue($request, $venue);
        $resource = $model->resources()->with('activity.venue.hours')->findOrFail($request->integer('resource_id'));
        $start = $request->startsAt();

        $count = $availability->block($resource, $start, $request->integer('slots'), $request->input('reason'), $request->user());

        $end = $start->addMinutes($resource->activity->slot_minutes * $count);
        $slots = BookingSlot::where('resource_id', $resource->id)->whereNull('booking_id')
            ->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->orderBy('starts_at')->get();

        return response()->json([
            'message' => __('api.blocks.created'),
            'data' => [
                'blocked' => $count,
                'slots' => $slots->map(fn (BookingSlot $slot) => [
                    'slot_id' => $slot->id,
                    'resource_id' => $slot->resource_id,
                    'starts_at' => $slot->starts_at->toIso8601String(),
                    'ends_at' => $slot->starts_at->copy()->addMinutes($resource->activity->slot_minutes)->toIso8601String(),
                    'block_reason' => $slot->block_reason,
                ])->values(),
            ],
        ], 201);
    }

    /** Put one blocked slot back on sale. A slot held by a booking is refused (409 slot_taken). */
    public function unblock(Request $request, int $venue, int $slot, AvailabilityService $availability): JsonResponse
    {
        $model = $this->venue($request, $venue);
        $held = BookingSlot::whereIn('resource_id', $model->resources()->pluck('resources.id'))->findOrFail($slot);

        $availability->unblock($held);

        return response()->json(['message' => __('api.blocks.removed')]);
    }

    /**
     * Held slots (bookings and blocks) of the venue's resources inside the day's window,
     * keyed by "resource_id:start timestamp".
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     */
    private function heldSlots(Venue $venue, array $window): Collection
    {
        return BookingSlot::whereIn('resource_id', $venue->activities->flatMap->resources->pluck('id'))
            ->where('starts_at', '>=', $window[0]->utc())
            ->where('starts_at', '<', $window[1]->utc())
            ->with('booking.order.user')
            ->get()
            ->keyBy(fn (BookingSlot $slot) => $slot->resource_id.':'.$slot->starts_at->getTimestamp());
    }

    private function booking($booking, bool $withCustomer): array
    {
        return [
            'id' => $booking->id,
            'code' => $booking->code,
            'status' => $booking->status?->toArray(),
            'starts_at' => $booking->starts_at?->toIso8601String(),
            'ends_at' => $booking->ends_at?->toIso8601String(),
            'slots' => $booking->slots,
            'total' => $booking->total,
            'checked_in_at' => $booking->checked_in_at?->toIso8601String(),
            'order' => $booking->order ? [
                'id' => $booking->order->id,
                'number' => $booking->order->number,
                'channel' => $booking->order->channel,
                'status' => $booking->order->status?->toArray(),
            ] : null,
            'customer' => $withCustomer && $booking->order ? [
                'name' => $booking->order->customerDisplayName(),
                'phone' => $booking->order->customer_phone,
            ] : null,
        ];
    }
}
