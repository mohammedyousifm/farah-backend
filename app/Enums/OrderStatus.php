<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Completed = 'completed';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return __('enums.order_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PendingPayment => 'warning',
            self::Confirmed => 'success',
            self::Cancelled => 'gray',
            self::Refunded => 'danger',
            self::PartiallyRefunded => 'warning',
            self::Completed => 'info',
            self::Expired => 'gray',
        };
    }
}
