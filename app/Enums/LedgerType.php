<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LedgerType: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Sale = 'sale';
    case Commission = 'commission';
    case Refund = 'refund';
    case CommissionReversal = 'commission_reversal';
    case Payout = 'payout';
    case Adjustment = 'adjustment';

    public function getLabel(): string
    {
        return __('enums.ledger_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sale => 'success',
            self::Commission => 'primary',
            self::Refund => 'danger',
            self::CommissionReversal => 'warning',
            self::Payout => 'info',
            self::Adjustment => 'gray',
        };
    }
}
