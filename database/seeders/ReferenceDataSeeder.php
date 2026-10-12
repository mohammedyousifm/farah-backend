<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\City;
use App\Models\CommissionRule;
use App\Models\Page;
use App\Models\RecommendationRule;
use App\Models\Sector;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Master data the platform needs to run (safe for production). Idempotent. */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->cities();
        $this->sectors();
        $this->commission();
        $this->recommendationRules();
        $this->pages();
    }

    private function cities(): void
    {
        $cities = [
            ['الرياض', 'Riyadh', 'الرياض', 24.7136, 46.6753],
            ['جدة', 'Jeddah', 'مكة المكرمة', 21.4858, 39.1925],
            ['مكة المكرمة', 'Makkah', 'مكة المكرمة', 21.3891, 39.8579],
            ['المدينة المنورة', 'Madinah', 'المدينة المنورة', 24.5247, 39.5692],
            ['الدمام', 'Dammam', 'الشرقية', 26.4207, 50.0888],
            ['الخبر', 'Khobar', 'الشرقية', 26.2172, 50.1971],
            ['الأحساء', 'Al Ahsa', 'الشرقية', 25.3833, 49.5867],
            ['أبها', 'Abha', 'عسير', 18.2164, 42.5053],
            ['الطائف', 'Taif', 'مكة المكرمة', 21.2854, 40.4152],
            ['تبوك', 'Tabuk', 'تبوك', 28.3835, 36.5662],
            ['العلا', 'AlUla', 'المدينة المنورة', 26.6084, 37.9232],
            ['بريدة', 'Buraydah', 'القصيم', 26.3592, 43.9818],
        ];

        foreach ($cities as $i => [$ar, $en, $region, $lat, $lng]) {
            City::updateOrCreate(['name_en' => $en], ['name_ar' => $ar, 'region' => $region, 'lat' => $lat, 'lng' => $lng, 'sort_order' => $i]);
        }
    }

    private function sectors(): void
    {
        // Sectors are the activity types customers browse by ("I want to play billiards").
        $sectors = [
            ['name' => ['بلياردو', 'Billiards'], 'icon' => 'billiards', 'license' => false,
                'categories' => [['بلياردو أمريكي', 'Pool'], ['سنوكر', 'Snooker']]],
            ['name' => ['بولينج', 'Bowling'], 'icon' => 'bowling', 'license' => false, 'categories' => []],
            ['name' => ['قيمنق', 'Gaming'], 'icon' => 'gaming', 'license' => false,
                'categories' => [['بلايستيشن', 'PlayStation'], ['PC', 'PC Gaming'], ['واقع افتراضي', 'VR'], ['غرف خاصة', 'Private rooms']]],
            ['name' => ['كارتنج', 'Go-Kart'], 'icon' => 'go-kart', 'license' => true, 'categories' => []],
            ['name' => ['رماية', 'Shooting'], 'icon' => 'shooting', 'license' => true,
                'categories' => [['رماية بالسهام', 'Archery'], ['بينتبول', 'Paintball'], ['ليزر تاق', 'Laser tag']]],
            ['name' => ['تنس الطاولة', 'Table Tennis'], 'icon' => 'table-tennis', 'license' => false, 'categories' => []],
            ['name' => ['دارت', 'Darts'], 'icon' => 'darts', 'license' => false, 'categories' => []],
            ['name' => ['ملاعب', 'Sports Courts'], 'icon' => 'courts', 'license' => false,
                'categories' => [['بادل', 'Padel'], ['كرة قدم', 'Football'], ['كرة سلة', 'Basketball'], ['تنس', 'Tennis']]],
            ['name' => ['ألعاب الجلسات', 'Majlis Games'], 'icon' => 'majlis-games', 'license' => false,
                'categories' => [['بلوت', 'Baloot'], ['جاكارو', 'Jakaroo'], ['كيرم', 'Carrom'], ['فرفيرة', 'Foosball'], ['دومينو', 'Dominoes']]],
            ['name' => ['تجارب ترفيهية', 'Experiences'], 'icon' => 'experiences', 'license' => false,
                'categories' => [['غرف الهروب', 'Escape rooms'], ['كاريوكي', 'Karaoke'], ['رمي الفؤوس', 'Axe throwing']]],
        ];

        foreach ($sectors as $i => $s) {
            $sector = Sector::updateOrCreate(['slug' => Str::slug($s['name'][1])], [
                'name_ar' => $s['name'][0],
                'name_en' => $s['name'][1],
                'icon' => $s['icon'],
                'requires_license' => $s['license'],
                'sort_order' => $i,
            ]);

            foreach ($s['categories'] as $j => [$ar, $en]) {
                Category::updateOrCreate(['slug' => Str::slug($en)], ['sector_id' => $sector->id, 'name_ar' => $ar, 'name_en' => $en, 'sort_order' => $j]);
            }
        }
    }

    private function commission(): void
    {
        CommissionRule::firstOrCreate(['scope' => 'global', 'version' => 1], [
            'name' => 'العمولة الأساسية',
            'type' => 'percentage',
            'rate' => 10,
            'effective_from' => now()->subYear(),
        ]);

        $experiences = Sector::where('slug', 'experiences')->first();
        CommissionRule::firstOrCreate(['scope' => 'sector', 'scope_id' => $experiences->id, 'version' => 1], [
            'name' => 'عمولة التجارب الترفيهية',
            'type' => 'percentage_plus_fixed',
            'rate' => 7.5,
            'fixed_amount' => 2,
            'effective_from' => now()->subYear(),
        ]);
    }

    private function recommendationRules(): void
    {
        $rules = [
            ['نفس الفئة التي شاهدها العميل', 'same_category', 40, ['limit' => 20]],
            ['نفس القطاع', 'same_sector', 20, ['limit' => 20]],
            ['مشابه للمفضلة', 'favorites_similar', 30, ['limit' => 20]],
            ['الأكثر طلباً في مدينة العميل', 'popular_in_city', 25, ['limit' => 20]],
            ['الرائج آخر أسبوعين', 'trending', 15, ['days' => 14, 'limit' => 20]],
            ['الأعلى تقييماً', 'top_rated', 10, ['min_reviews' => 3, 'limit' => 20]],
            ['المضافة حديثاً', 'latest', 5, ['limit' => 20]],
        ];

        foreach ($rules as [$name, $strategy, $weight, $params]) {
            RecommendationRule::updateOrCreate(['strategy' => $strategy], ['name' => $name, 'weight' => $weight, 'params' => $params]);
        }
    }

    private function pages(): void
    {
        $pages = [
            'terms' => ['الشروط والأحكام', 'Terms & Conditions'],
            'privacy' => ['سياسة الخصوصية', 'Privacy Policy'],
            'refund-policy' => ['سياسة الاسترداد', 'Refund Policy'],
            'about' => ['عن فرة', 'About Farah'],
        ];

        foreach ($pages as $slug => [$ar, $en]) {
            Page::firstOrCreate(['slug' => $slug], [
                'title_ar' => $ar,
                'title_en' => $en,
                'body_ar' => '<p>يُحدَّث هذا المحتوى من لوحة الإدارة ← المحتوى ← الصفحات.</p>',
                'body_en' => '<p>Edit this content from Admin → Content → Pages.</p>',
            ]);
        }
    }
}
