<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Where wallet money comes from (refund, compensation, reward) and the one way it leaves (payment). */
enum WalletTransactionType: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Refund = 'refund';
    case Compensation = 'compensation';
    case Reward = 'reward';
    case Payment = 'payment';

    public function getLabel(): string
    {
        return __('enums.wallet_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Refund => 'info',
            self::Compensation => 'warning',
            self::Reward => 'success',
            self::Payment => 'gray',
        };
    }

    /** The types an admin may add by hand. */
    public static function grantable(): array
    {
        return [self::Compensation, self::Reward];
    }
}
