<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InternalRating: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Green = 'green';
    case Yellow = 'yellow';
    case Red = 'red';

    public function getLabel(): string
    {
        return __('enums.internal_rating.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Green => 'success',
            self::Yellow => 'warning',
            self::Red => 'danger',
        };
    }
}
