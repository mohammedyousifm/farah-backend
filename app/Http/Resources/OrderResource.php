<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Order for the customer app; the provider app gets the same shape plus
 * commission / net figures and customer contact details.
 *
 * @mixin Order
 *
 * @property-read bool|null $reviews_exists set by the controllers that list or show a customer's orders
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isProvider = $request->attributes->has('provider');

        return [
            'id' => $this->id,
            'number' => $this->number,
            'channel' => $this->channel,
            'status' => $this->status?->toArray(),
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'vat_amount' => $this->vat_amount,
            'total' => $this->total,
            'refunded_amount' => $this->refunded_amount,
            'currency' => $this->currency,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'venue' => new VenueBriefResource($this->whenLoaded('venue')),
            'provider' => new ProviderSummaryResource($this->whenLoaded('provider')),
            'bookings' => BookingResource::collection($this->whenLoaded('bookings', fn () => $this->bookings->each(fn ($booking) => $this->nest($booking)))),
            'payment' => new PaymentResource($this->whenLoaded('payment')),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
            'has_review' => $this->when(isset($this->reviews_exists), fn () => (bool) $this->reviews_exists),
            'customer' => $this->when($isProvider, fn () => [
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
                'email' => $this->customer_email,
            ]),
            'offline_payment_method' => $this->when($isProvider, $this->offline_payment_method),
            'notes' => $this->when($isProvider, $this->notes),
            'commission_amount' => $this->when($isProvider, $this->commission_amount),
            'provider_net' => $this->when($isProvider, $this->provider_net),
        ];
    }

    /** Bookings inside an order carry the same shape as on their own: point them back at this order and its venue. */
    private function nest($booking): void
    {
        $booking->setRelation('order', $this->resource->withoutRelations());
        if ($this->relationLoaded('venue')) {
            $booking->setRelation('venue', $this->venue);
        }
    }
}
