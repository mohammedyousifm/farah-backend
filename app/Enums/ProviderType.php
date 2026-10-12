<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProviderType: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Establishment = 'establishment';

    public function getLabel(): string
    {
        return __('enums.provider_type.'.$this->value);
    }

    public function getColor(): string
    {
        return 'primary';
    }
}
