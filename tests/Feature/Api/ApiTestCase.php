<?php

namespace Tests\Feature\Api;

use App\Enums\ProviderStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Enums\VenueStatus;
use App\Models\Activity;
use App\Models\City;
use App\Models\Provider;
use App\Models\Sector;
use App\Models\User;
use App\Models\Venue;
use App\Services\VenueService;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixtures for the API tests. Uses the reference data seeder (cities,
 * sectors, commission rules) and builds providers / venues inline.
 *
 * Run: php artisan test tests/Feature/Api   (database farah_test, see phpunit.xml)
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected Sector $sector;

    protected City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, ReferenceDataSeeder::class]);

        $this->sector = Sector::where('slug', 'billiards')->firstOrFail();
        $this->city = City::orderBy('id')->firstOrFail();
    }

    protected function customer(string $phone = '+966501111111', array $attributes = []): User
    {
        return User::create($attributes + [
            'name' => 'Customer '.substr($phone, -4),
            'phone' => $phone,
            'type' => UserType::Customer,
            'status' => UserStatus::Active,
            'locale' => 'ar',
        ]);
    }

    /** Approved provider (approved in $this->sector) with its owner membership. */
    protected function provider(?User $owner = null, string $name = 'Provider'): Provider
    {
        $owner ??= $this->customer('+9665'.random_int(10000000, 99999999));
        $owner->update(['type' => UserType::Provider]);

        $provider = Provider::create([
            'owner_id' => $owner->id,
            'type' => 'establishment',
            'name_ar' => $name.' AR',
            'name_en' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'city_id' => $this->city->id,
            'cr_number' => '1010'.random_int(100000, 999999),
            'status' => ProviderStatus::Approved,
            'approved_at' => now(),
        ]);
        $provider->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'is_active' => true]);
        $provider->sectors()->attach($this->sector->id, ['status' => 'approved']);

        return $provider;
    }

    /**
     * Published, bookable venue open every day 10:00 -> 00:00 (Riyadh), with one activity:
     * billiards, 100 SAR / hour, 60-minute slots, 1-4 slots per booking, 3 tables.
     */
    protected function venue(Provider $provider, array $attributes = [], array $activity = [], int $resources = 3): Venue
    {
        $venue = $provider->venues()->create($attributes + [
            'city_id' => $this->city->id,
            'name_ar' => 'مركز تجريبي',
            'name_en' => 'Test venue',
            'slug' => 'test-venue-'.Str::lower(Str::random(6)),
            'refund_policy' => 'flexible',
            'status' => VenueStatus::Published,
            'published_at' => now(),
        ]);
        app(VenueService::class)->syncHours($venue, VenueService::defaultHours());

        $created = $venue->activities()->create($activity + [
            'sector_id' => $this->sector->id,
            'name_ar' => 'بلياردو',
            'name_en' => 'Billiards',
            'price_per_hour' => 100,
            'slot_minutes' => 60,
            'min_slots' => 1,
            'max_slots' => 4,
        ]);

        for ($i = 1; $i <= $resources; $i++) {
            $created->resources()->create(['name_ar' => "طاولة {$i}", 'name_en' => "Table {$i}", 'sort_order' => $i]);
        }

        return $venue->fresh();
    }

    protected function activity(Venue $venue): Activity
    {
        return $venue->activities()->with('resources')->firstOrFail();
    }

    /** A slot start inside working hours: $days from today at $time, Riyadh wall clock. */
    protected function slot(int $days = 3, string $time = '20:00'): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Riyadh')->addDays($days)->setTimeFromTimeString($time)->utc();
    }

    /** One booking line for OrderService / the checkout endpoint. */
    protected function line(Venue $venue, int $slots = 2, ?CarbonImmutable $startsAt = null, ?int $resourceId = null): array
    {
        return array_filter([
            'activity_id' => $this->activity($venue)->id,
            'resource_id' => $resourceId,
            'starts_at' => ($startsAt ?? $this->slot())->toIso8601String(),
            'slots' => $slots,
        ], fn ($v) => $v !== null);
    }
}
