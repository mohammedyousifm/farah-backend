<?php

namespace App\Enums;

trait EnumHelpers
{
    /** @return array<string, string> value => translated label */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->all();
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Shape used by the API so the mobile app can render a status badge. */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->getLabel(), 'color' => $this->getColor()];
    }
}
