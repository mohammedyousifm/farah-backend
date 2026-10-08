<?php

namespace App\Models\Concerns;

/**
 * Bilingual columns are stored as `<field>_ar` / `<field>_en`.
 * `$model->tr('name')` returns the value for the current locale,
 * falling back to Arabic (the platform's primary language).
 */
trait Localized
{
    public function tr(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        return $this->getAttribute("{$field}_{$locale}")
            ?: $this->getAttribute("{$field}_ar")
            ?: $this->getAttribute("{$field}_en");
    }
}
