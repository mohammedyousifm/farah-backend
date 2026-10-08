<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RefundStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Processed = 'processed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return __('enums.refund_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Approved => 'info',
            self::Rejected => 'danger',
            self::Processed => 'success',
            self::Failed => 'danger',
        };
    }
}
