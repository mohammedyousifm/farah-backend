<?php

namespace Database\Seeders;

use App\Enums\PlanFeature;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/** The free plan (activities only), the middle plan and the top plan, which sell visibility; admins change names, prices and features from Admin → Plans. Idempotent. */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name_ar' => 'الباقة المجانية', 'name_en' => 'Free', 'is_free' => true,
                'description_ar' => 'كل متجر يبدأ عليها بدون رسوم: أضف أنشطتك واستقبل الحجوزات.', 'description_en' => 'Every store starts on it at no cost: add your activities and take bookings.',
                'price' => 0, 'duration_days' => 30, 'sort_order' => 0,
                'items' => [],
            ],
            [
                'name_ar' => 'الباقة الاحترافية', 'name_en' => 'Pro',
                'description_ar' => 'ظهور متجرك في الواجهات الرئيسية للتطبيق، وصفحة له تظهر في محركات البحث.', 'description_en' => 'Your store on the app’s home pages, with a page that search engines show.',
                'price' => 499, 'duration_days' => 30, 'sort_order' => 1,
                'items' => [PlanFeature::HomeFeatured, PlanFeature::Seo],
            ],
            [
                'name_ar' => 'الباقة المميزة', 'name_en' => 'Premium',
                'description_ar' => 'أعلى البحث العام، الواجهات الرئيسية والمتحركة، المقترحات، ومحركات البحث.', 'description_en' => 'Top of search, home pages and moving banners, recommendations and search engines.',
                'price' => 899, 'duration_days' => 30, 'sort_order' => 2,
                'items' => [PlanFeature::SearchBoost, PlanFeature::HomeFeatured, PlanFeature::HomeSlider, PlanFeature::Recommended, PlanFeature::Seo],
            ],
        ];

        foreach ($plans as $row) {
            $items = $row['items'];
            unset($row['items']);

            $plan = Plan::firstOrCreate(['name_en' => $row['name_en']], $row);
            if (! $plan->wasRecentlyCreated) {
                continue;
            }
            foreach ($items as $feature) {
                $plan->items()->create(['feature' => $feature]);
            }
        }
    }
}
