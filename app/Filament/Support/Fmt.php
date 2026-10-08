<?php

namespace App\Filament\Support;

use Illuminate\Support\Facades\Lang;

class Fmt
{
    public static function sar(float|int|null $amount): string
    {
        return number_format((float) $amount, 2).' '.__('admin.currency');
    }

    /** Label for the current locale from an *_ar / *_en pair on an array row or model. */
    public static function tr(mixed $row, string $field): ?string
    {
        $locale = app()->getLocale();
        $get = fn ($k) => is_array($row) ? ($row[$k] ?? null) : $row?->{$k};

        return $get("{$field}_{$locale}") ?: $get("{$field}_ar") ?: $get("{$field}_en");
    }

    /** Column name for the current locale, e.g. name_ar. */
    public static function col(string $field): string
    {
        return $field.'_'.(app()->getLocale() === 'en' ? 'en' : 'ar');
    }

    /**
     * formatStateUsing() callback that swaps an Arabic column for the current locale's
     * value, e.g. Fmt::localized('provider.name') for TextColumn::make('provider.name_ar').
     */
    public static function localized(string $path): \Closure
    {
        $relation = str_contains($path, '.') ? substr($path, 0, strrpos($path, '.')) : null;
        $field = $relation ? substr($path, strrpos($path, '.') + 1) : $path;

        return function ($state, $record) use ($relation, $field) {
            if (app()->getLocale() === 'ar' || ! $record) {
                return $state;
            }
            $model = $relation ? data_get($record, $relation) : $record;

            return ($model ? static::tr($model, $field) : null) ?? $state;
        };
    }

    /** Translated label for a raw value from lang/<locale>/admin.php "<group>.<value>", or the value itself. */
    public static function label(string $group, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = $value instanceof \BackedEnum ? $value->value : (string) $value;
        $key = "admin.{$group}.{$value}";

        return Lang::has($key) ? __($key) : $value;
    }

    /** Phone numbers such as +9665... keep their order inside RTL text (a leading LRM mark). */
    public static function phone(?string $phone): ?string
    {
        return filled($phone) ? "\u{200E}".$phone : null;
    }
}
