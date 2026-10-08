<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'amount' => $this->amount,
            'refunded_amount' => $this->refunded_amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'status' => $this->status?->toArray(),
            'failure_reason' => $this->failure_reason,
            'checkout_url' => $this->status?->value === 'initiated' ? $this->checkout_url : null,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'orders' => $this->whenLoaded('orders', fn () => $this->orders->map(fn ($o) => [
                'id' => $o->id,
                'number' => $o->number,
                'status' => $o->status->toArray(),
            ])),
        ];
    }
}
