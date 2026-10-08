<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Initiated = 'initiated';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function getLabel(): string
    {
        return __('enums.payment_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Initiated => 'gray',
            self::Paid => 'success',
            self::Failed => 'danger',
            self::Refunded => 'danger',
            self::PartiallyRefunded => 'warning',
        };
    }
}
