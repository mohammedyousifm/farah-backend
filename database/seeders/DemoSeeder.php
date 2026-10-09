<?php

namespace Database\Seeders;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\OrderStatus;
use App\Enums\ProviderType;
use App\Enums\UserType;
use App\Enums\VenueStatus;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Category;
use App\Models\City;
use App\Models\Complaint;
use App\Models\HomeSection;
use App\Models\Order;
use App\Models\Provider;
use App\Models\Review;
use App\Models\Sector;
use App\Models\SectorRequest;
use App\Models\User;
use App\Models\Venue;
use App\Services\AvailabilityService;
use App\Services\CheckInService;
use App\Services\DisputeService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ProviderService;
use App\Services\RefundService;
use App\Services\SettlementService;
use App\Services\VenueService;
use App\Support\DocNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Realistic local demo data, created through the real services so the
 * slots, bookings, ledger, commissions and settlements are all consistent.
 */
class DemoSeeder extends Seeder
{
    private User $admin;

    private Collection $customers;

    private int $turn = 0;

    public function run(): void
    {
        if (Provider::exists()) {
            return; // already seeded
        }

        mt_srand(20261006); // same picks on every run (what is still free today depends on the clock)

        $this->admin = User::where('email', 'admin@farah.sa')->firstOrFail();
        auth()->setUser($this->admin);

        $images = $this->images();
        $this->customers = $this->customers();
        [$approved, $pending] = $this->providers();

        // Venues opened 6-8 weeks ago, so there is a booking history behind them.
        $venues = collect();
        foreach ($this->venueDefinitions() as $i => $def) {
            Carbon::setTestNow(now()->subDays(58 - $i * 2));
            $venues->push($this->venue($approved[$def['provider']], $def, $i, $images));
            Carbon::setTestNow();
        }
        $activities = $venues->flatMap(fn (Venue $v) => $v->activities()->with('resources', 'venue.hours')->get());

        // Past bookings: made N days ago for the following days, played (or missed), then completed.
        // The oldest are out of the hold period (settled below), the newest are still pending balance.
        foreach ([[40, 3, 3], [22, 3, 3], [6, 3, 2], [2, 1, 2]] as [$daysAgo, $span, $perActivity]) {
            $this->pastBookings($activities, $daysAgo, $span, $perActivity);
        }
        Carbon::setTestNow();

        // Today and the coming days: enough to make the availability grid a mix of booked and free.
        foreach ($activities as $activity) {
            foreach ([0 => 1.0, 1 => 0.9, 2 => 0.6, 3 => 0.4, 5 => 0.25, 7 => 0.15] as $days => $share) {
                $count = (int) ceil($activity->resources->count() * $share);
                for ($k = 0; $k < $count; $k++) {
                    $this->book($this->customer(), $activity, $days, $k * 3 + $days, mt_rand(1, 2));
                }
            }
        }

        // A group that took two tables in one order, and a provider block for maintenance.
        $billiards = $activities->first();
        $group = $this->book($this->customers[1], $billiards, 4, 0, 2, tables: 2);
        app(AvailabilityService::class)->block($billiards->resources->last(), $this->freeStart($billiards, 1, $billiards->resources->count() - 1, 2), 2, 'صيانة الطاولة', $billiards->venue->provider->owner);

        $this->counterBooking($activities->firstWhere('name_en', 'Bowling'));
        $this->otherVenues($approved, $pending, $images);
        $this->reviewsAndSupport($venues);
        $this->refunds($group, $billiards);
        $this->favorites($venues);
        $this->homeSections($venues, $images);

        // What the scheduler would have done, then the finance cycle.
        app(OrderService::class)->expireStale();
        app(OrderService::class)->completeFinished();
        app(SettlementService::class)->generateAll(now(), $this->admin);
        if ($settlement = $approved[0]->settlements()->first()) {
            app(SettlementService::class)->approve($settlement, $this->admin);
            app(SettlementService::class)->markPaid($settlement->fresh(), $this->admin, 'TRX-'.now()->format('ymd').'-0001');
        }

        SectorRequest::create([
            'provider_id' => $approved[3]->id,
            'name_ar' => 'ألعاب الطاولة',
            'name_en' => 'Board Games',
            'description' => 'لدينا ركن لألعاب الطاولة والورق يُحجز بالساعة ونرغب بإضافته كنوع نشاط مستقل.',
        ]);
    }

    /** Gradient cover images in the brand palette (GD, no external downloads). */
    private function images(): array
    {
        $palette = [
            [[249, 178, 51], [230, 0, 126]],
            [[230, 0, 126], [123, 47, 247]],
            [[123, 47, 247], [61, 139, 255]],
            [[61, 139, 255], [0, 200, 180]],
            [[255, 94, 98], [255, 153, 102]],
            [[40, 20, 80], [230, 0, 126]],
            [[16, 94, 78], [61, 139, 255]],
            [[20, 24, 60], [123, 47, 247]],
        ];
        $paths = [];

        foreach ($palette as $i => [$from, $to]) {
            $path = "demo/cover-{$i}.jpg";
            if (! Storage::disk('public')->exists($path)) {
                $w = 1200;
                $h = 675;
                $img = imagecreatetruecolor($w, $h);
                for ($x = 0; $x < $w; $x++) {
                    $t = $x / $w;
                    $c = imagecolorallocate($img, ...array_map(fn ($a, $b) => (int) ($a + ($b - $a) * $t), $from, $to));
                    imageline($img, $x, 0, $x, $h, $c);
                }
                // soft circles for depth
                for ($k = 0; $k < 6; $k++) {
                    $alpha = imagecolorallocatealpha($img, 255, 255, 255, 110);
                    imagefilledellipse($img, random_int(0, $w), random_int(0, $h), $r = random_int(120, 420), $r, $alpha);
                }
                ob_start();
                imagejpeg($img, null, 85);
                Storage::disk('public')->put($path, ob_get_clean());
                imagedestroy($img);
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /** Publishes a venue photo from database/seeders/demo-photos (see CREDITS.md there) and returns its path on the public disk. */
    private function photo(string $name): string
    {
        $path = "demo/venues/{$name}.jpg";
        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, file_get_contents(__DIR__."/demo-photos/{$name}.jpg"));
        }

        return $path;
    }

    private function customers(): Collection
    {
        $names = ['سارة العتيبي', 'محمد القحطاني', 'نورة الشمري', 'عبدالله الدوسري', 'ريم الحربي', 'فيصل الغامدي',
            'لمى الزهراني', 'خالد المطيري', 'هيا السبيعي', 'تركي العنزي', 'جود الشهري', 'ماجد البقمي'];
        $cities = City::pluck('id');

        return collect($names)->map(fn ($name, $i) => User::create([
            'name' => $name,
            'phone' => '+96650'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT),
            'email' => "customer{$i}@example.com",
            'phone_verified_at' => now(),
            'type' => UserType::Customer,
            'city_id' => $cities[$i % 3],
            'tracking_consent' => true,
            'created_at' => now()->subDays(60 - $i * 4),
        ]));
    }

    /** Customers take turns so everyone ends up with a few bookings. */
    private function customer(): User
    {
        return $this->customers[$this->turn++ % $this->customers->count()];
    }

    /** @return array{0: array<int, Provider>, 1: array<int, Provider>} approved providers, providers still waiting */
    private function providers(): array
    {
        $service = app(ProviderService::class);
        $sectors = Sector::pluck('id', 'slug');
        $city = fn (string $name) => City::where('name_en', $name)->value('id');

        // One provider = one store. The last two are still waiting: one sent its store with the request, one has not added it yet.
        $defs = [
            ['شركة كيو للترفيه', 'Cue Entertainment Co.', ProviderType::Establishment, '1010456781', ['billiards'], 'Riyadh', 'green', 'approved'],
            ['نادي سبين الرياضي', 'Spin Sports Club', ProviderType::Establishment, '1010733402', ['table-tennis', 'darts'], 'Riyadh', 'green', 'approved'],
            ['مؤسسة سترايك للبولينج', 'Strike Bowling Est.', ProviderType::Establishment, '4030221453', ['bowling', 'billiards'], 'Jeddah', 'yellow', 'approved'],
            ['بيكسل قيمنق', 'Pixel Gaming', ProviderType::Individual, null, ['gaming'], 'Riyadh', 'green', 'approved'],
            ['مؤسسة لغز للترفيه', 'Loghz Entertainment Est.', ProviderType::Establishment, '2051334706', ['experiences'], 'Khobar', 'green', 'approved'],
            ['شركة أبكس للكارتنج', 'Apex Karting Co.', ProviderType::Establishment, '2050778812', ['go-kart'], 'Dammam', 'green', 'approved'],
            ['ملاعب أبكس الرياضية', 'Apex Courts Co.', ProviderType::Establishment, '4030665129', ['sports-courts'], 'Jeddah', 'green', 'approved'],
            ['مؤسسة سترايك مكة', 'Strike Makkah Est.', ProviderType::Establishment, '4031220984', ['bowling'], 'Makkah', 'green', 'approved'],
            ['كيو لاونج النخيل', 'Cue Lounge Al Nakheel', ProviderType::Establishment, '1010998455', ['billiards', 'darts'], 'Riyadh', null, 'pending'],
            ['نادي الهدف للرماية', 'Hadaf Shooting Club', ProviderType::Establishment, '4030998877', ['shooting'], 'Jeddah', null, 'pending'],
        ];

        $providers = [];
        foreach ($defs as $i => [$ar, $en, $type, $cr, $sectorSlugs, $cityName, $rating, $state]) {
            $owner = User::create([
                'name' => 'مالك '.$ar,
                'phone' => '+96655'.str_pad((string) (2000000 + $i), 7, '0', STR_PAD_LEFT),
                'email' => "provider{$i}@example.com",
                'password' => 'password', // provider web dashboard login (/provider)
                'phone_verified_at' => now(),
                'type' => UserType::Customer,
            ]);

            $provider = $service->register($owner, [
                'type' => $type,
                'name_ar' => $ar,
                'name_en' => $en,
                'bio_ar' => 'متجر ترفيه مجهز بأحدث الطاولات والأجهزة، يُحجز بالساعة ويستقبل الأفراد والمجموعات.',
                'email' => $owner->email,
                'phone' => $owner->phone,
                'city_id' => $city($cityName),
                'cr_number' => $cr,
                'national_id' => $cr ? null : '10'.random_int(10000000, 99999999),
                'vat_number' => $cr ? '3'.random_int(10000000000000, 99999999999999) : null,
                'bank_name' => 'مصرف الراجحي',
                'iban' => 'SA'.random_int(10, 99).'8000000'.random_int(100000000, 999999999).'1234', // SA + 22 digits
                'account_holder' => $ar,
                'internal_rating' => $rating,
                'sector_ids' => collect($sectorSlugs)->map(fn ($s) => $sectors[$s])->all(),
            ]);

            $isPending = $state === 'pending';
            $docs = $cr
                ? [[DocumentType::CommercialRegister, $cr, now()->addMonths($i === 2 ? 1 : 14)], [DocumentType::VatCertificate, null, null]]
                : [[DocumentType::NationalId, $provider->national_id, now()->addYears(3)]];
            $docs[] = [DocumentType::IbanLetter, null, null];
            if (array_intersect(['go-kart', 'shooting'], $sectorSlugs)) {
                $docs[] = [DocumentType::License, 'LIC-'.random_int(10000, 99999), now()->addMonths(10)]; // karting / shooting need a licence
            }

            foreach ($docs as [$docType, $number, $expires]) {
                $provider->documents()->create([
                    'type' => $docType,
                    'number' => $number,
                    'file_path' => 'demo/cover-0.jpg',
                    'issued_at' => now()->subYear(),
                    'expires_at' => $expires,
                    'status' => $isPending ? DocumentStatus::Pending : DocumentStatus::Approved,
                    'reviewed_by' => $isPending ? null : $this->admin->id,
                    'reviewed_at' => $isPending ? null : now(),
                ]);
            }

            if (! $isPending) {
                $service->approve($provider, $this->admin);
                $provider->update(['approved_at' => now()->subDays(65)]);
            }

            $providers[] = $provider->fresh();
        }

        return [
            array_values(array_filter($providers, fn (Provider $p) => $p->isApproved())),
            array_values(array_filter($providers, fn (Provider $p) => ! $p->isApproved())),
        ];
    }

    /**
     * The published venues. hours = [opens, closes] for every day; days overrides a weekday (0 = Sunday),
     * null = closed. activities = [sector, category, ar, en, price / hour, slot minutes, min, max slots, players, resource ar, en, count].
     */
    private function venueDefinitions(): array
    {
        return [
            [
                'provider' => 0, 'city' => 'Riyadh', 'name' => ['كيو لاونج للبلياردو والسنوكر', 'Cue Lounge Billiards & Snooker'],
                'summary' => ['طاولات بلياردو وسنوكر احترافية وأجواء هادئة حتى بعد منتصف الليل.', 'Tournament pool and snooker tables in a relaxed lounge, open past midnight.'],
                'address' => 'حي العليا، طريق الملك فهد، الرياض', 'audience' => 'men', 'min_age' => 16, 'policy' => 'flexible', 'featured' => true,
                'photos' => ['billiards-1', 'billiards-2', 'billiards-3'],
                'hours' => ['16:00', '02:00'], 'days' => [4 => ['16:00', '03:00'], 5 => ['16:00', '03:00']],
                'activities' => [
                    ['billiards', 'pool', 'بلياردو', 'Pool', 60, 60, 1, 4, 4, 'طاولة', 'Table', 8],
                    ['billiards', 'snooker', 'سنوكر', 'Snooker', 80, 60, 1, 3, 4, 'طاولة سنوكر', 'Snooker table', 3],
                ],
            ],
            [
                'provider' => 1, 'city' => 'Riyadh', 'name' => ['نادي سبين لتنس الطاولة والدارت', 'Spin Table Tennis & Darts Club'],
                'summary' => ['طاولات تنس معتمدة ولوحات دارت إلكترونية، للتحديات السريعة مع الأصدقاء.', 'Competition tables and electronic dart boards for quick matches with friends.'],
                'address' => 'حي الملقا، طريق أنس بن مالك، الرياض', 'audience' => 'all', 'min_age' => null, 'policy' => 'flexible', 'featured' => false,
                'photos' => ['tabletennis-1', 'darts-1', 'tabletennis-2', 'darts-2'],
                'hours' => ['15:00', '00:00'], 'days' => [5 => ['16:00', '01:00']],
                'activities' => [
                    ['table-tennis', null, 'تنس الطاولة', 'Table tennis', 40, 30, 2, 6, 4, 'طاولة', 'Table', 6],
                    ['darts', null, 'دارت', 'Darts', 30, 30, 2, 6, 6, 'لوحة', 'Board', 4],
                ],
            ],
            [
                'provider' => 2, 'city' => 'Jeddah', 'name' => ['سترايك بولينج', 'Strike Bowling Centre'],
                'summary' => ['ستة مسارات بولينج بإضاءة ليلية وشاشات تسجيل تلقائي، مناسبة للعائلات.', 'Six glow-in-the-dark lanes with automatic scoring, family friendly.'],
                'address' => 'حي الشاطئ، طريق الكورنيش، جدة', 'audience' => 'families', 'min_age' => null, 'policy' => 'moderate', 'featured' => true,
                'photos' => ['bowling-1', 'bowling-2', 'bowling-3'],
                'hours' => ['13:00', '01:00'], 'days' => [5 => ['16:00', '02:00']],
                'activities' => [
                    ['bowling', null, 'بولينج', 'Bowling', 140, 60, 1, 3, 6, 'مسار', 'Lane', 6],
                    ['billiards', 'pool', 'بلياردو', 'Pool', 55, 60, 1, 3, 4, 'طاولة', 'Table', 4],
                ],
            ],
            [
                'provider' => 3, 'city' => 'Riyadh', 'name' => ['بيكسل قيمنق لاونج', 'Pixel Gaming Lounge'],
                'summary' => ['غرف بلايستيشن 5 خاصة ومحطات PC بكروت RTX وشاشات 240 هرتز.', 'Private PlayStation 5 rooms and RTX gaming PCs with 240 Hz screens.'],
                'address' => 'حي حطين، طريق الأمير تركي الأول، الرياض', 'audience' => 'all', 'min_age' => 12, 'policy' => 'flexible', 'featured' => true,
                'photos' => ['gaming-1', 'gaming-2', 'gaming-3'],
                'hours' => ['14:00', '03:00'], 'days' => [5 => ['16:00', '04:00']],
                'activities' => [
                    ['gaming', 'private-rooms', 'غرفة بلايستيشن 5', 'PlayStation 5 room', 90, 60, 1, 5, 6, 'غرفة', 'Room', 4],
                    ['gaming', 'pc-gaming', 'محطة PC', 'Gaming PC', 20, 30, 2, 8, 1, 'جهاز', 'PC', 10],
                    ['gaming', 'vr', 'واقع افتراضي', 'VR booth', 120, 30, 1, 4, 2, 'منصة VR', 'VR booth', 2],
                ],
            ],
            [
                'provider' => 4, 'city' => 'Khobar', 'name' => ['لغز لغرف الهروب', 'Loghz Escape Rooms'],
                'summary' => ['أربع غرف هروب بقصص مختلفة ومستويات صعوبة متدرجة لفرق من 2 إلى 6.', 'Four story-driven escape rooms for teams of 2 to 6, from easy to expert.'],
                'address' => 'حي اليرموك، طريق الملك سلمان، الخبر', 'audience' => 'all', 'min_age' => 10, 'policy' => 'moderate', 'featured' => false,
                'photos' => ['escape-1', 'escape-2', 'escape-3'],
                'hours' => ['16:00', '00:00'], 'days' => [0 => null, 4 => ['16:00', '01:00'], 5 => ['16:00', '01:00']],
                'activities' => [
                    ['experiences', 'escape-rooms', 'غرفة هروب', 'Escape room', 280, 60, 1, 2, 6, 'غرفة', 'Room', 4],
                ],
            ],
            [
                'provider' => 5, 'city' => 'Dammam', 'name' => ['حلبة أبكس للكارتنج', 'Apex Karting Track'],
                'summary' => ['حلبة خارجية بطول 900 متر وسيارات كارت 270cc مع توقيت إلكتروني لكل لفة.', 'A 900 m outdoor circuit with 270cc karts and electronic lap timing.'],
                'address' => 'حي الشاطئ الغربي، طريق الخليج، الدمام', 'audience' => 'all', 'min_age' => 14, 'policy' => 'strict', 'featured' => true,
                'photos' => ['karting-1', 'karting-2', 'karting-3'],
                'hours' => ['16:00', '00:00'], 'days' => [4 => ['16:00', '02:00'], 5 => ['16:00', '02:00']],
                'activities' => [
                    ['go-kart', null, 'كارتنج', 'Go-kart', 240, 15, 1, 4, 1, 'كارت', 'Kart', 6],
                ],
            ],
            [
                'provider' => 6, 'city' => 'Jeddah', 'name' => ['ملاعب أبكس للبادل وكرة القدم', 'Apex Padel & Football Courts'],
                'summary' => ['ملاعب بادل زجاجية وملاعب خماسية بعشب صناعي وإضاءة ليلية.', 'Glass padel courts and five-a-side pitches with artificial turf and floodlights.'],
                'address' => 'حي الروضة، شارع الأمير سلطان، جدة', 'audience' => 'all', 'min_age' => null, 'policy' => 'moderate', 'featured' => false,
                'photos' => ['courts-1', 'courts-2', 'courts-3'],
                'hours' => ['06:00', '02:00'], 'days' => [5 => ['15:00', '02:00']],
                'activities' => [
                    ['sports-courts', 'padel', 'بادل', 'Padel', 180, 60, 1, 3, 4, 'ملعب', 'Court', 3],
                    ['sports-courts', 'football', 'كرة قدم خماسية', 'Five-a-side football', 300, 60, 1, 2, 10, 'ملعب', 'Pitch', 2],
                ],
            ],
        ];
    }

    /** Create a venue with its hours, media, activities and resources; publish it unless told otherwise. */
    private function venue(Provider $provider, array $def, int $seed, array $images, bool $publish = true): Venue
    {
        $service = app(VenueService::class);
        $city = City::where('name_en', $def['city'])->firstOrFail();
        [$ar, $en] = $def['name'];
        // The first photo is the cover, the rest is the gallery; gradients when a venue has none.
        $photos = array_map($this->photo(...), $def['photos'] ?? []);
        $cover = $photos[0] ?? $images[$seed % count($images)];
        $gallery = $photos ? array_slice($photos, 1) : array_map(fn (int $n) => $images[($seed + $n) % count($images)], range(1, 2 + $seed % 2));

        $hours = [];
        foreach (range(0, 6) as $weekday) {
            $day = array_key_exists($weekday, $def['days'] ?? []) ? $def['days'][$weekday] : $def['hours'];
            $hours[] = ['weekday' => $weekday, 'opens_at' => $day[0] ?? null, 'closes_at' => $day[1] ?? null, 'is_closed' => $day === null];
        }

        $venue = $service->create($provider, [
            'city_id' => $city->id,
            'name_ar' => $ar,
            'name_en' => $en,
            'summary_ar' => $def['summary'][0],
            'summary_en' => $def['summary'][1],
            'description_ar' => '<p>'.$def['summary'][0].'</p><p>احجز طاولتك أو غرفتك بالساعة من التطبيق، وأبرز رمز الحجز عند الوصول.</p>',
            'description_en' => '<p>'.$def['summary'][1].'</p><p>Book by the hour in the app and show your booking code on arrival.</p>',
            'cover_image' => $cover,
            'address' => $def['address'],
            'lat' => round($city->lat + (($seed % 5) - 2) * 0.012, 7),
            'lng' => round($city->lng + (($seed % 3) - 1) * 0.015, 7),
            'phone' => '+96611'.str_pad((string) (4000000 + $seed * 137), 7, '0', STR_PAD_LEFT),
            'audience' => $def['audience'],
            'min_age' => $def['min_age'],
            'refund_policy' => $def['policy'],
            'is_featured' => $def['featured'],
        ], $hours);

        foreach ($gallery as $n => $path) {
            $venue->media()->create(['type' => 'image', 'path' => $path, 'sort_order' => $n + 1]);
        }

        $sectors = Sector::pluck('id', 'slug');
        foreach ($def['activities'] as $order => [$sector, $category, $nameAr, $nameEn, $price, $minutes, $min, $max, $players, $unitAr, $unitEn, $count]) {
            $activity = $venue->activities()->create([
                'sector_id' => $sectors[$sector],
                'category_id' => $category ? Category::where('slug', $category)->value('id') : null,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'price_per_hour' => $price,
                'slot_minutes' => $minutes,
                'min_slots' => $min,
                'max_slots' => $max,
                'capacity' => $players,
                'sort_order' => $order,
            ]);
            foreach (range(1, $count) as $n) {
                $activity->resources()->create(['name_ar' => "{$unitAr} {$n}", 'name_en' => "{$unitEn} {$n}", 'sort_order' => $n]);
            }
        }

        if ($publish) {
            $service->submit($venue);
            $service->approve($venue, $this->admin);
        }

        return $venue->fresh();
    }

    /** A free run of slots on resource #$r of the activity, $days from today, picked towards the evening. */
    private function freeLine(Activity $activity, int $days, int $r, int $slots): ?array
    {
        $slots = max($activity->min_slots, min($slots, $activity->max_slots));
        $date = CarbonImmutable::now(AvailabilityService::timezone())->addDays($days)->format('Y-m-d');
        $day = app(AvailabilityService::class)->forDate($activity, $date);
        $row = $day['resources'][$r % max(1, count($day['resources']))] ?? null;
        $states = array_column($row['slots'] ?? [], 'status');
        $n = count($states);

        if ($n < $slots) {
            return null; // closed that day
        }

        $from = max(0, $n - $slots - mt_rand(0, (int) ($n * 0.6)));
        for ($i = 0; $i < $n; $i++) {
            $start = ($from + $i) % $n;
            if ($start + $slots <= $n && array_unique(array_slice($states, $start, $slots)) === ['available']) {
                return [
                    'activity_id' => $activity->id,
                    'resource_id' => $row['resource']->id,
                    'starts_at' => $row['slots'][$start]['starts_at']->toIso8601String(),
                    'slots' => $slots,
                ];
            }
        }

        return null;
    }

    private function freeStart(Activity $activity, int $days, int $r, int $slots): CarbonImmutable
    {
        return CarbonImmutable::parse($this->freeLine($activity, $days, $r, $slots)['starts_at']);
    }

    /** Book (and normally pay) $tables resources of the activity in one order. Null when nothing is free. */
    private function book(User $customer, Activity $activity, int $days, int $r, int $slots = 1, int $tables = 1, bool $pay = true): ?Order
    {
        $lines = [];
        for ($t = 0; $t < $tables; $t++) {
            if ($line = $this->freeLine($activity, $days, $r + $t, $slots)) {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            return null;
        }

        $order = app(OrderService::class)->createOnline($customer, $lines);
        $payment = app(PaymentService::class)->checkout($customer, collect([$order]));
        if ($pay) {
            app(PaymentService::class)->markPaid($payment->fresh(), 'fake_seed', ['mada', 'visa', 'applepay'][mt_rand(0, 2)]);
        }

        return $order->fresh();
    }

    /**
     * Time travel: book as if it were $daysAgo days ago, check most customers in at their
     * start time, then let the scheduler complete what was played and review some of it.
     */
    private function pastBookings(Collection $activities, int $daysAgo, int $span, int $perActivity): void
    {
        Carbon::setTestNow();
        $base = now()->subDays($daysAgo);
        Carbon::setTestNow($base);

        $orders = collect();
        foreach ($activities as $activity) {
            for ($k = 0; $k < $perActivity; $k++) {
                $orders->push($this->book($this->customer(), $activity, 1 + $k % $span, $k + $daysAgo, mt_rand(1, 3)));
            }
        }
        $orders = $orders->filter()->values();

        // One customer never paid: the hold expires and the slots go back on sale.
        $this->book($this->customer(), $activities->first(), 1, 5, 1, pay: false);

        $bookings = Booking::whereIn('order_id', $orders->pluck('id'))->with('venue.provider.owner')->orderBy('starts_at')->get();
        foreach ($bookings as $i => $booking) {
            if ($i % 7 === 6) {
                continue; // no-show
            }
            Carbon::setTestNow($booking->starts_at->copy()->addMinutes(5));
            app(CheckInService::class)->checkIn($booking->venue->provider, $booking->code, $booking->venue_id, $booking->venue->provider->owner);
        }

        Carbon::setTestNow($base->copy()->addDays($span + 1));
        app(OrderService::class)->expireStale();
        app(OrderService::class)->completeFinished();

        $comments = [
            [5, 'الطاولات ممتازة والمكان نظيف، الحجز من التطبيق كان سهل جداً.'],
            [4, 'تجربة جميلة، وصلنا ولقينا الطاولة جاهزة في الموعد.'],
            [5, 'أفضل مكان جربته، الموظفون متعاونون والأسعار مناسبة.'],
            [3, 'المكان مزدحم في نهاية الأسبوع والتكييف ضعيف قليلاً.'],
            [4, null],
            [5, 'رجعنا أكثر من مرة، أنصح بالحجز المسبق.'],
            [2, 'تأخروا علينا ربع ساعة قبل ما تجهز الغرفة.'],
        ];
        foreach ($orders as $i => $order) {
            if ($i % 5 > 2 || $order->fresh()->status !== OrderStatus::Completed) {
                continue;
            }
            [$rating, $comment] = $comments[($i + $daysAgo) % count($comments)];
            $review = Review::create([
                'user_id' => $order->user_id,
                'venue_id' => $order->venue_id,
                'provider_id' => $order->provider_id,
                'order_id' => $order->id,
                'rating' => $rating,
                'comment' => $comment,
            ]);
            if ($rating <= 3 && $i % 2 === 0) {
                $review->update(['provider_reply' => 'نعتذر عن ذلك ونشكرك على ملاحظتك، تم التنبيه على الفريق وننتظر زيارتك القادمة.', 'replied_at' => now()]);
            }
        }
    }

    /** A walk-in customer paying cash at the counter for the next free hour. */
    private function counterBooking(Activity $activity): void
    {
        $line = $this->freeLine($activity, 0, 1, 1) ?? $this->freeLine($activity, 1, 1, 1);
        $provider = $activity->venue->provider;

        $order = app(OrderService::class)->createOffline($provider, [$line], ['name' => 'عميل حضوري', 'phone' => '+966500009999'], 'cash', $provider->owner);
        app(CheckInService::class)->checkIn($provider, $order->bookings->first()->code, null, $provider->owner); // already at the counter
    }

    /** A store its (approved) provider is still preparing, and a new provider that sent its store with its request. */
    private function otherVenues(array $approved, array $pending, array $images): void
    {
        $this->venue($approved[7], [
            'city' => 'Makkah', 'name' => ['سترايك بولينج مكة', 'Strike Bowling Makkah'],
            'summary' => ['ثمانية مسارات بولينج، الافتتاح قريباً.', 'Eight bowling lanes, opening soon.'],
            'address' => 'حي العوالي، طريق الملك عبدالله، مكة المكرمة', 'audience' => 'families', 'min_age' => null, 'policy' => 'moderate', 'featured' => false,
            'photos' => ['bowling-4', 'bowling-5'],
            'hours' => ['14:00', '01:00'],
            'activities' => [['bowling', null, 'بولينج', 'Bowling', 130, 60, 1, 3, 6, 'مسار', 'Lane', 8]],
        ], 8, $images, publish: false);

        $review = $this->venue($pending[0], [
            'city' => 'Riyadh', 'name' => ['كيو لاونج النخيل', 'Cue Lounge Al Nakheel'],
            'summary' => ['ست طاولات بلياردو وركن دارت.', 'Six pool tables and a darts corner.'],
            'address' => 'حي النخيل، طريق الإمام سعود، الرياض', 'audience' => 'men', 'min_age' => 16, 'policy' => 'flexible', 'featured' => false,
            'photos' => ['billiards-4', 'billiards-5'],
            'hours' => ['16:00', '02:00'],
            'activities' => [
                ['billiards', 'pool', 'بلياردو', 'Pool', 65, 60, 1, 4, 4, 'طاولة', 'Table', 6],
                ['darts', null, 'دارت', 'Darts', 30, 30, 2, 6, 6, 'لوحة', 'Board', 2],
            ],
        ], 9, $images, publish: false);
        app(VenueService::class)->submit($review);
    }

    private function reviewsAndSupport(Collection $venues): void
    {
        $order = Order::where('status', OrderStatus::Completed)->whereNotNull('user_id')->latest('id')->first();
        $complaint = Complaint::create([
            'number' => DocNumber::make('CMP', 'complaints'),
            'user_id' => $order->user_id,
            'provider_id' => $order->provider_id,
            'order_id' => $order->id,
            'venue_id' => $order->venue_id,
            'subject' => 'الطاولة لم تكن جاهزة في موعد الحجز',
            'body' => 'وصلت في الموعد وانتظرت عشرين دقيقة حتى انتهى العميل السابق، وحُسب الوقت من بداية حجزي.',
            'priority' => 'normal',
        ]);
        $complaint->replies()->create(['user_id' => $this->admin->id, 'body' => 'نعتذر عن ذلك، تم التواصل مع المكان وسنوافيك بالنتيجة خلال يوم عمل.']);

        Complaint::create([
            'number' => DocNumber::make('CMP', 'complaints'),
            'user_id' => $this->customers[3]->id,
            'type' => 'suggestion',
            'subject' => 'اقتراح: تنبيه عند توفر موعد',
            'body' => 'أتمنى إضافة تنبيه عندما تتوفر فترة في مكاني المفضل مساء الخميس.',
            'priority' => 'low',
        ]);

        Complaint::create([
            'number' => DocNumber::make('CMP', 'complaints'),
            'user_id' => $this->customers[6]->id,
            'venue_id' => $venues[3]->id,
            'provider_id' => $venues[3]->provider_id,
            'type' => 'feedback',
            'subject' => 'ذراع التحكم في الغرفة 2 لا يعمل جيداً',
            'body' => 'أحد أذرع التحكم في غرفة 2 يفصل أثناء اللعب، أرجو استبداله.',
            'priority' => 'high',
            'status' => 'in_progress',
            'assigned_to' => $this->admin->id,
        ]);

        $disputed = Order::where('status', OrderStatus::Completed)->whereNotNull('user_id')->where('id', '!=', $order->id)->latest('id')->first();
        app(DisputeService::class)->open($disputed, $this->admin, 'المكان كان مغلقاً وقت الحجز',
            'يقول العميل إنه وصل في موعده ووجد المكان مغلقاً للصيانة دون إشعار مسبق.', $disputed->total);
    }

    private function refunds(?Order $group, Activity $activity): void
    {
        $refunds = app(RefundService::class);
        $upcoming = Order::where('status', OrderStatus::Confirmed)->whereNotNull('user_id')
            ->when($group, fn ($q) => $q->where('id', '!=', $group->id))
            ->whereDoesntHave('bookings', fn ($q) => $q->where('starts_at', '<', now()->addDays(4)))
            ->orderBy('id')->get();

        // Requested refund waiting for admin review.
        if ($o = $upcoming->get(0)) {
            try {
                $refunds->request($o, $o->user, null, 'ظرف طارئ ولن أتمكن من الحضور');
            } catch (\Throwable) {
            }
        }

        // Booking cancelled and refunded in full: the slot is back on sale.
        if ($o = $upcoming->get(1)) {
            $refunds->createByAdmin($o, $o->total, null, 'ألغى العميل الحجز قبل الموعد بوقت كافٍ', $this->admin);
        }

        // Compensation without cancelling the booking.
        if ($o = $upcoming->get(2)) {
            $refunds->createByAdmin($o, round($o->total / 4, 2), null, 'تعويض جزئي عن تغيير الطاولة', $this->admin);
        }

        // One of the group's two tables is given back, the other stays booked.
        if ($group && $group->bookings->count() > 1) {
            $refunds->approve($refunds->request($group, $group->user, [['booking_id' => $group->bookings->last()->id]], 'أحد الأصدقاء اعتذر'), $this->admin);
        }

        // Cancelled by the customer before paying, and one checkout still waiting for payment.
        if ($o = $this->book($this->customers[8], $activity, 2, 3, 1, pay: false)) {
            app(OrderService::class)->cancelUnpaid($o, 'customer_cancelled');
        }
        $this->book($this->customers[9], $activity, 3, 4, 2, pay: false);
    }

    private function favorites(Collection $venues): void
    {
        foreach ($this->customers->take(8) as $i => $customer) {
            $customer->favorites()->syncWithoutDetaching([$venues[$i % $venues->count()]->id, $venues[($i + 3) % $venues->count()]->id]);
        }
    }

    private function homeSections(Collection $venues, array $images): void
    {
        $hero = HomeSection::create(['title_ar' => null, 'type' => 'banner', 'layout' => 'hero', 'source' => 'manual', 'sort_order' => 0]);
        foreach ($venues->where('is_featured', true)->where('status', VenueStatus::Published)->take(3)->values() as $i => $venue) {
            $hero->items()->create([
                'title_ar' => $venue->name_ar,
                'title_en' => $venue->name_en,
                'media_type' => 'image',
                'media_path' => $venue->cover_image,
                'link_type' => 'venue',
                'link_value' => (string) $venue->id,
                'sort_order' => $i,
            ]);
        }
        $hero->items()->create([
            'title_ar' => 'ليلة بولينج مع العائلة',
            'title_en' => 'Family bowling night',
            'media_type' => 'image',
            'media_path' => $this->photo('bowling-2'),
            'link_type' => 'sector',
            'link_value' => (string) Sector::where('slug', 'bowling')->value('id'),
            'sort_order' => 3,
        ]);

        HomeSection::create(['title_ar' => 'وش ودك تلعب؟', 'title_en' => 'What do you want to play?', 'type' => 'sectors', 'layout' => 'carousel', 'source' => 'latest', 'sort_order' => 1]);
        HomeSection::create(['title_ar' => 'مقترحة لك', 'title_en' => 'Recommended for you', 'type' => 'venues', 'layout' => 'carousel', 'source' => 'recommended', 'sort_order' => 2]);
        HomeSection::create(['title_ar' => 'الأعلى تقييماً', 'title_en' => 'Top rated', 'type' => 'venues', 'layout' => 'grid', 'source' => 'top_rated', 'sort_order' => 3]);
        HomeSection::create(['title_ar' => 'أماكن مميزة', 'title_en' => 'Featured venues', 'type' => 'venues', 'layout' => 'carousel', 'source' => 'featured', 'sort_order' => 4]);
        HomeSection::create(['title_ar' => 'أضيفت حديثاً', 'title_en' => 'Newly added', 'type' => 'venues', 'layout' => 'list', 'source' => 'latest', 'sort_order' => 5]);
        HomeSection::create([
            'title_ar' => 'عروض نهاية الأسبوع', 'title_en' => 'Weekend picks', 'type' => 'venues', 'layout' => 'carousel', 'source' => 'featured',
            'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(12), 'sort_order' => 6,
        ]);
        HomeSection::create(['title_ar' => 'مقدمو خدمات مميزون', 'title_en' => 'Top providers', 'type' => 'providers', 'layout' => 'carousel', 'source' => 'top_rated', 'sort_order' => 7]);
    }
}
