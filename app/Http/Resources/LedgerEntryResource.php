<?php

namespace App\Http\Resources;

use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LedgerEntry */
class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $state = match (true) {
            $this->type?->value === 'payout' => 'paid_out',
            $this->settlement_id !== null => 'in_settlement',
            $this->available_at && $this->available_at->isFuture() => 'pending',
            default => 'available',
        };

        return [
            'id' => $this->id,
            'type' => $this->type?->toArray(),
            'amount' => $this->amount,
            'description' => $this->description,
            'order_id' => $this->order_id,
            'order_number' => $this->whenLoaded('order', fn () => $this->order?->number),
            'refund_id' => $this->refund_id,
            'settlement_id' => $this->settlement_id,
            'state' => $state,
            'available_at' => $this->available_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
