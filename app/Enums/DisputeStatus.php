<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DisputeStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Open = 'open';
    case UnderReview = 'under_review';
    case ResolvedCustomer = 'resolved_customer';
    case ResolvedProvider = 'resolved_provider';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return __('enums.dispute_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::UnderReview => 'info',
            self::ResolvedCustomer => 'success',
            self::ResolvedProvider => 'success',
            self::Closed => 'gray',
        };
    }
}
