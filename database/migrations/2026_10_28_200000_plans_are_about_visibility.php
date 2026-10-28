<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plans now sell visibility only (2026-10-28): the activity limit is gone (every store adds as many activities as
 * it wants), and the three tiers are the free plan (no features), the middle plan (home pages + search engines)
 * and the top plan (general search, home pages, moving banners, recommendations, search engines).
 * The seeded "Basic" plan no longer has a place between them and is switched off (kept for its history).
 */
return new class extends Migration
{
    private const MIDDLE = ['home_featured', 'seo'];

    private const TOP = ['search_boost', 'home_featured', 'home_slider', 'recommended', 'seo'];

    public function up(): void
    {
        DB::table('plan_features')->where('feature', 'max_activities')->delete();
        DB::table('subscription_features')->where('feature', 'max_activities')->delete();

        $plans = DB::table('plans')->pluck('id', 'name_en');

        foreach (['Pro' => self::MIDDLE, 'Premium' => self::TOP] as $name => $features) {
            if (! isset($plans[$name])) {
                continue;
            }
            DB::table('plan_features')->where('plan_id', $plans[$name])->delete();
            DB::table('plan_features')->insert(array_map(fn (string $feature) => [
                'plan_id' => $plans[$name], 'feature' => $feature, 'value' => null, 'days' => null,
            ], $features));
        }

        DB::table('plans')->where('name_en', 'Pro')->update([
            'description_ar' => 'ظهور متجرك في الواجهات الرئيسية للتطبيق، وصفحة له تظهر في محركات البحث.',
            'description_en' => 'Your store on the app\'s home pages, with a page that search engines show.',
        ]);
        DB::table('plans')->where('name_en', 'Premium')->update([
            'description_ar' => 'أعلى البحث العام، الواجهات الرئيسية والمتحركة، المقترحات، ومحركات البحث.',
            'description_en' => 'Top of search, home pages and moving banners, recommendations and search engines.',
        ]);
        DB::table('plans')->where('name_en', 'Free')->update([
            'description_ar' => 'كل متجر يبدأ عليها بدون رسوم: أضف أنشطتك واستقبل الحجوزات.',
            'description_en' => 'Every store starts on it at no cost: add your activities and take bookings.',
        ]);
        DB::table('plans')->where('name_en', 'Basic')->update(['is_active' => false]);
    }

    public function down(): void
    {
        // Data change only; the old limits are not restored.
    }
};
