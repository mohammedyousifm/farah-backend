<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a plan can grant: ways for a store to be seen more. Adding activities is never a plan feature (every
 * store, free plan included, adds as many as it wants). The list lives in code because the server enforces each one:
 *   - SearchBoost: the store is placed above the others in the general search;
 *   - HomeFeatured: the store appears in the "featured" rows of the app's home page;
 *   - HomeSlider: the store gets a slide in the moving banner at the top of the home page;
 *   - Recommended: the store is placed in "recommended for you" lists;
 *   - Seo: the store gets a public web page that search engines index (in the sitemap, with rich data).
 */
enum PlanFeature: string implements HasLabel
{
    use EnumHelpers;

    case SearchBoost = 'search_boost';
    case HomeFeatured = 'home_featured';
    case HomeSlider = 'home_slider';
    case Recommended = 'recommended';
    case Seo = 'seo';

    public function getLabel(): string
    {
        return __('enums.plan_feature.'.$this->value);
    }

    public function getColor(): string
    {
        return 'primary';
    }

    /** A limit feature would carry a number (how many); every feature today is simply on or off. */
    public function hasValue(): bool
    {
        return false;
    }
}
