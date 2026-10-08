<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProviderType: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Individual = 'individual';
    case Establishment = 'establishment';

    public function getLabel(): string
    {
        return __('enums.provider_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Individual => 'info',
            self::Establishment => 'primary',
        };
    }
}
