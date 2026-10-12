<?php

namespace App\Http\Resources;

use App\Enums\WalletTransactionType;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WalletTransaction */
class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->toArray(),
            'amount' => $this->amount,
            'balance_after' => $this->balance_after,
            'order_id' => $this->order_id,
            'order_number' => $this->order?->number,
            // The admin's note is shown for compensation and rewards (it says what the money is for).
            'note' => in_array($this->type, WalletTransactionType::grantable(), true) ? $this->note : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
