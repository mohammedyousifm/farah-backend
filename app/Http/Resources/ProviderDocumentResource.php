<?php

namespace App\Http\Resources;

use App\Models\ProviderDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProviderDocument */
class ProviderDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->toArray(),
            'title' => $this->title,
            'number' => $this->number,
            'sector_id' => $this->sector_id,
            'file_url' => $this->fileUrl(),
            'issued_at' => $this->issued_at?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'expiring_soon' => $this->isExpiringSoon(),
            'status' => $this->status?->toArray(),
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
