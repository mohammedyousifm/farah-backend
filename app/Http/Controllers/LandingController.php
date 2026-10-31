<?php

namespace App\Http\Controllers;

use App\Enums\ProviderStatus;
use App\Http\Controllers\Web\SiteController;
use App\Models\City;
use App\Models\Plan;
use App\Models\Provider;
use App\Models\Sector;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Public marketing page (/). Arabic by default, ?lang=en|ar switches and is kept
 * in the same session key as the dashboards so /provider/login follows it.
 */
class LandingController extends SiteController
{
    public function __invoke(Request $request): View
    {
        $sectors = Sector::where('is_active', true)
            ->with(['categories' => fn ($q) => $q->where('is_active', true)->whereNull('parent_id')->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return $this->render($request, 'landing.index', [
            'sectors' => $sectors,
            'plans' => Plan::active()->with('items')->orderByDesc('is_free')->orderBy('sort_order')->orderBy('price')->get(),
            'stats' => array_filter(self::stats()),
        ]);
    }

    /** Live platform counts for the stats strip, cached for 10 minutes. A zero is hidden by the caller. */
    public static function stats(): array
    {
        return Cache::remember('landing.stats', 600, fn () => [
            'providers' => Provider::where('status', ProviderStatus::Approved)->count(),
            'venues' => Venue::visible()->count(),
            'sectors' => Sector::where('is_active', true)->count(),
            'cities' => City::where('is_active', true)->count(),
        ]);
    }
}
