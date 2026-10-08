<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BookingStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Pending = 'pending';         // slots held, waiting for payment
    case Confirmed = 'confirmed';     // paid, customer has not arrived yet
    case CheckedIn = 'checked_in';    // customer arrived
    case Completed = 'completed';     // time passed
    case Cancelled = 'cancelled';     // cancelled or refunded, slots released
    case Expired = 'expired';         // payment window passed, slots released

    /** Statuses that keep the resource reserved. */
    public const HOLDING = ['pending', 'confirmed', 'checked_in', 'completed'];

    public function getLabel(): string
    {
        return __('enums.booking_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'success',
            self::CheckedIn => 'info',
            self::Completed => 'info',
            self::Cancelled => 'gray',
            self::Expired => 'gray',
        };
    }
}
