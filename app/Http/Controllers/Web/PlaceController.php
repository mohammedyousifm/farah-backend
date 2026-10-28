<?php

namespace App\Http\Controllers\Web;

use App\Enums\PlanFeature;
use App\Models\Venue;
use App\Services\SubscriptionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public web page of a store at /places/{slug}: the "search engine visibility" plan feature. Only stores whose
 * plan includes it have a page (and a sitemap entry); for every other store the address answers 404.
 */
class PlaceController extends SiteController
{
    public function __invoke(Request $request, string $slug): View
    {
        $venue = self::indexable()->where('slug', $slug)
            ->with(['city', 'hours', 'activities' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->firstOrFail();

        return $this->render($request, 'site.place', ['venue' => $venue]);
    }

    /** Visible venues of stores holding the SEO feature right now. */
    public static function indexable(): Builder
    {
        $subscriptions = app(SubscriptionService::class);
        $query = Venue::visible();

        // On the free plan everyone has it: no filter. Otherwise only the stores with a running paid feature.
        return $subscriptions->freeItem(PlanFeature::Seo)
            ? $query
            : $query->whereIn('provider_id', $subscriptions->providerIdsWith(PlanFeature::Seo) ?: [0]);
    }
}
