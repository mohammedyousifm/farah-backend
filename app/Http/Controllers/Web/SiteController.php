<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Support\SiteLocale;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Base for the public website pages: locale + the data every page's header/footer needs. */
abstract class SiteController extends Controller
{
    /** Static pages served at /{slug}, in footer order. */
    public const PAGE_SLUGS = ['about', 'terms', 'privacy', 'refund-policy'];

    protected function render(Request $request, string $view, array $data = []): View
    {
        SiteLocale::apply($request);

        return view($view, $data + [
            'isRtl' => app()->getLocale() === 'ar',
            'supportPhone' => Setting::get('support_phone'),
            'supportEmail' => Setting::get('support_email'),
            'footerPages' => self::publishedPages(),
        ]);
    }

    /** slug => Page (published static pages, footer order). One tiny query, kept fresh so an unpublished page is never linked. */
    public static function publishedPages(): array
    {
        return Page::where('is_published', true)
            ->whereIn('slug', self::PAGE_SLUGS)
            ->get(['id', 'slug', 'title_ar', 'title_en', 'updated_at'])
            ->sortBy(fn (Page $page) => array_search($page->slug, self::PAGE_SLUGS, true))
            ->keyBy('slug')
            ->all();
    }
}
