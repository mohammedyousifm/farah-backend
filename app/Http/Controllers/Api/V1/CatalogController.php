<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\CityResource;
use App\Http\Resources\HomeSectionResource;
use App\Http\Resources\PageResource;
use App\Http\Resources\SectorResource;
use App\Models\Category;
use App\Models\City;
use App\Models\Page;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\Venue;
use App\Services\AvailabilityService;
use App\Services\HomeFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Public reference data and the home feed. */
class CatalogController extends Controller
{
    public function config(): JsonResponse
    {
        return response()->json(['data' => [
            'platform_name' => app()->getLocale() === 'en' ? Setting::get('platform_name_en') : Setting::get('platform_name_ar'),
            'currency' => config('farah.currency'),
            'vat_rate' => (float) Setting::get('vat_rate'),
            'prices_include_vat' => (bool) Setting::get('prices_include_vat'),
            'support' => [
                'phone' => Setting::get('support_phone'),
                'email' => Setting::get('support_email'),
            ],
            'min_app_version' => [
                'ios' => Setting::get('min_app_version_ios'),
                'android' => Setting::get('min_app_version_android'),
            ],
            'maintenance_mode' => (bool) Setting::get('maintenance_mode'),
            'order_payment_timeout_minutes' => (int) Setting::get('order_payment_timeout_minutes'),
            'max_bookings_per_order' => (int) Setting::get('max_bookings_per_order'),
            // Working hours and slot times (`time`, `end_time`) are wall-clock in this timezone.
            'timezone' => AvailabilityService::timezone(),
            'otp' => [
                'length' => 4,
                'resend_seconds' => config('farah.otp.resend_seconds'),
            ],
            'refund_policies' => collect(config('farah.refund_policies'))->map(fn ($rules, $key) => [
                'key' => $key,
                'label' => __("api.policies.{$key}.label"),
                'description' => __("api.policies.{$key}.description"),
                'rules' => collect($rules)->map(fn ($pct, $hours) => ['min_hours_before_start' => $hours, 'refund_percent' => $pct])->values(),
            ])->values(),
            'audiences' => ['all', 'families', 'men', 'women', 'kids'],
            'pages' => Page::where('is_published', true)->pluck('slug'),
        ]]);
    }

    public function cities(): AnonymousResourceCollection
    {
        return CityResource::collection(City::where('is_active', true)->orderBy('sort_order')->orderBy('name_ar')->get());
    }

    /** Activity types (billiards, bowling, ...) with how many bookable venues offer each. */
    public function sectors(): AnonymousResourceCollection
    {
        $sectors = Sector::where('is_active', true)
            ->select('sectors.*')
            ->selectSub(Venue::visible()->selectRaw('COUNT(*)')->whereHas('activities', fn ($q) => $q->where('is_active', true)
                ->whereColumn('activities.sector_id', 'sectors.id')), 'venues_count')
            ->with(['categories' => fn ($q) => $q->where('is_active', true)->whereNull('parent_id')
                ->with(['children' => fn ($c) => $c->where('is_active', true)->orderBy('sort_order')])])
            ->orderBy('sort_order')
            ->get();

        return SectorResource::collection($sectors);
    }

    public function categories(Request $request): AnonymousResourceCollection
    {
        $categories = Category::where('is_active', true)
            ->when($request->integer('sector_id'), fn ($q, $id) => $q->where('sector_id', $id))
            ->when($request->has('parent_id'), fn ($q) => $q->where('parent_id', $request->integer('parent_id') ?: null))
            ->whereHas('sector', fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function home(Request $request, HomeFeedService $feed): AnonymousResourceCollection
    {
        $user = $request->user('sanctum');
        $cityId = $request->integer('city_id') ?: $user?->city_id;

        return HomeSectionResource::collection($feed->build($user, $cityId));
    }

    public function page(string $slug): PageResource
    {
        return new PageResource(Page::where('slug', $slug)->where('is_published', true)->firstOrFail());
    }
}
