<?php

namespace Database\Seeders;

use App\Enums\ProviderStatus;
use App\Enums\UserType;
use App\Enums\VenueStatus;
use App\Models\City;
use App\Models\Provider;
use App\Models\Sector;
use App\Models\User;
use App\Services\VenueService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data for the local load test (never run on a real database): LOADTEST_USERS customers, each with an API
 * token, and LOADTEST_PROVIDERS extra approved providers with one published store (2 activities, 4-6 tables
 * each). Writes the tokens to LOADTEST_OUT (CSV: user_id,token) for the load tool.
 *
 *   DB_DATABASE=farah_load php artisan db:seed --class=LoadTestSeeder
 */
class LoadTestSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(str_contains((string) config('database.connections.mysql.database'), 'load'), 500, 'LoadTestSeeder runs only on a *load* database.');

        // Process environment of the load-test run (not .env), so getenv() and not env(): it also works with a config cache.
        $users = (int) (getenv('LOADTEST_USERS') ?: 50000);
        $providers = (int) (getenv('LOADTEST_PROVIDERS') ?: 200);
        $out = (string) (getenv('LOADTEST_OUT') ?: storage_path('app/loadtest-tokens.csv'));

        $this->providers($providers);
        $this->customers($users, $out);
    }

    private function providers(int $count): void
    {
        $cities = City::pluck('id')->all();
        $sectors = Sector::where('is_active', true)->get();
        $service = app(VenueService::class);

        for ($i = 1; $i <= $count; $i++) {
            $owner = User::create([
                'name' => "مالك متجر {$i}", 'email' => "lt-provider{$i}@example.com", 'password' => 'password',
                'phone' => '+96656'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'type' => UserType::Provider, 'status' => 'active', 'email_verified_at' => now(),
            ]);
            $provider = Provider::create([
                'owner_id' => $owner->id, 'type' => 'establishment',
                'name_ar' => "متجر الاختبار {$i}", 'name_en' => "Load Store {$i}",
                'slug' => 'load-store-'.$i, 'city_id' => $cities[$i % count($cities)],
                'cr_number' => (string) (2000000000 + $i), 'status' => ProviderStatus::Approved, 'approved_at' => now(),
            ]);
            $provider->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'is_active' => true]);

            $picked = $sectors->shuffle()->take(2);
            $provider->sectors()->sync($picked->mapWithKeys(fn ($s) => [$s->id => ['status' => 'approved']])->all());

            $venue = $provider->venues()->create([
                'city_id' => $provider->city_id, 'name_ar' => "صالة الاختبار {$i}", 'name_en' => "Load Venue {$i}",
                'slug' => 'load-venue-'.$i, 'refund_policy' => 'moderate',
                'status' => VenueStatus::Published, 'published_at' => now(),
            ]);
            $service->syncHours($venue, VenueService::defaultHours());

            foreach ($picked as $n => $sector) {
                $activity = $venue->activities()->create([
                    'sector_id' => $sector->id, 'name_ar' => $sector->name_ar, 'name_en' => $sector->name_en,
                    'price_per_hour' => [80, 100, 120, 150][$i % 4], 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 3,
                ]);
                $tables = 4 + ($i + $n) % 3;
                for ($t = 1; $t <= $tables; $t++) {
                    $activity->resources()->create(['name_ar' => "طاولة {$t}", 'name_en' => "Table {$t}", 'sort_order' => $t]);
                }
            }
        }
        $this->command?->info("{$count} providers with stores");
    }

    private function customers(int $count, string $out): void
    {
        $cities = City::pluck('id')->all();
        $now = now()->toDateTimeString();
        $fh = fopen($out, 'w');
        fwrite($fh, "user_id,token\n");

        foreach (array_chunk(range(1, $count), 1000) as $chunk) {
            DB::table('users')->insert(array_map(fn (int $i) => [
                'name' => "عميل اختبار {$i}", 'phone' => '+96657'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'type' => 'customer', 'status' => 'active', 'locale' => 'ar', 'city_id' => $cities[$i % count($cities)],
                'phone_verified_at' => $now, 'tracking_consent' => $i % 3 === 0, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));

            $ids = DB::table('users')->whereIn('phone', array_map(fn (int $i) => '+96657'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), $chunk))->pluck('id');
            $plain = [];
            DB::table('personal_access_tokens')->insert($ids->map(function (int $id) use (&$plain, $now) {
                $plain[$id] = Str::random(40);

                return ['tokenable_type' => 'user', 'tokenable_id' => $id, 'name' => 'loadtest', 'token' => hash('sha256', $plain[$id]),
                    'abilities' => '["*"]', 'created_at' => $now, 'updated_at' => $now];
            })->all());

            DB::table('personal_access_tokens')->where('name', 'loadtest')->whereIn('tokenable_id', $ids)->get(['id', 'tokenable_id'])
                ->each(fn ($row) => fwrite($fh, "{$row->tokenable_id},{$row->id}|{$plain[$row->tokenable_id]}\n"));
        }
        fclose($fh);
        $this->command?->info("{$count} customers with tokens -> {$out}");
    }
}
