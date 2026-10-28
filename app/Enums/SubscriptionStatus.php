<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * A provider's subscription to a plan. "Expired" is not stored: an active subscription whose
 * end date has passed simply stops granting anything (see ProviderSubscription::isCurrent()).
 */
enum SubscriptionStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Pending = 'pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('enums.subscription_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            self::Rejected => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
