<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserType: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Customer = 'customer';
    case Provider = 'provider';
    case Admin = 'admin';

    public function getLabel(): string
    {
        return __('enums.user_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Customer => 'info',
            self::Provider => 'warning',
            self::Admin => 'primary',
        };
    }
}
