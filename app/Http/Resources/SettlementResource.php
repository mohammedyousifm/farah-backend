<?php

namespace App\Http\Resources;

use App\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Settlement */
class SettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'gross_amount' => $this->gross_amount,
            'commission_amount' => $this->commission_amount,
            'refunds_amount' => $this->refunds_amount,
            'adjustments_amount' => $this->adjustments_amount,
            'net_amount' => $this->net_amount,
            'status' => $this->status?->toArray(),
            'bank' => $this->bank_snapshot,
            'payment_reference' => $this->payment_reference,
            'receipt_url' => $this->resource->receiptUrl(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'entries' => LedgerEntryResource::collection($this->whenLoaded('entries')),
        ];
    }
}
