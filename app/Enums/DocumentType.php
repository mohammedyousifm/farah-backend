<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DocumentType: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case NationalId = 'national_id';
    case CommercialRegister = 'commercial_register';
    case License = 'license';
    case VatCertificate = 'vat_certificate';
    case IbanLetter = 'iban_letter';
    case Other = 'other';

    public function getLabel(): string
    {
        return __('enums.document_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NationalId => 'gray',
            self::CommercialRegister => 'gray',
            self::License => 'gray',
            self::VatCertificate => 'gray',
            self::IbanLetter => 'gray',
            self::Other => 'gray',
        };
    }
}
