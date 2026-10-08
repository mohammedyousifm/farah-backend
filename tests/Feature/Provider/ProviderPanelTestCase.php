<?php

namespace Tests\Feature\Provider;

use App\Enums\UserType;
use App\Models\Order;
use App\Models\Provider;
use App\Models\User;
use App\Models\Venue;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Tests\Feature\Api\ApiTestCase;

/**
 * Fixtures for the provider web dashboard (/provider): an approved provider with its
 * owner and one published venue (billiards, 100 SAR / hour, 3 tables, open 10:00 -> 00:00).
 *
 * Run with: DB_DATABASE=farah_test_provider php artisan test tests/Feature/Provider
 */
abstract class ProviderPanelTestCase extends ApiTestCase
{
    protected Provider $provider;

    protected User $owner;

    protected Venue $venue;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@farah.sa', 'password' => 'password',
            'type' => UserType::Admin, 'status' => 'active',
        ]);
        $this->admin->assignRole('super_admin');

        $this->owner = $this->customer('+966500000001', ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->provider = $this->provider($this->owner, 'Layali');
        $this->venue = $this->venue($this->provider);
    }

    protected function panel(?Provider $tenant = null): void
    {
        Filament::setCurrentPanel(Filament::getPanel('provider'));
        Filament::setTenant($tenant ?? $this->provider);
    }

    /** Signed-in owner with the provider panel and tenant set (what Livewire tests need). */
    protected function asOwner(): void
    {
        $this->actingAs($this->owner, 'provider');
        $this->panel();
    }

    /** A staff member of $this->provider with exactly these permissions. */
    protected function staff(array $permissions, string $email = 'staff@example.com'): User
    {
        $staff = User::create(['name' => 'Staff', 'email' => $email, 'password' => 'password', 'type' => UserType::Customer, 'status' => 'active']);
        $this->provider->members()->create(['user_id' => $staff->id, 'role' => 'staff', 'permissions' => $permissions, 'is_active' => true]);

        return $staff;
    }

    /** A confirmed counter booking on $venue (default: table 1, in 3 days at 20:00, 2 slots). */
    protected function booking(?Venue $venue = null, ?CarbonImmutable $startsAt = null, int $slots = 2, ?int $resourceId = null): Order
    {
        $venue ??= $this->venue;
        $resourceId ??= $this->activity($venue)->resources->first()->id;

        return app(OrderService::class)->createOffline(
            $venue->provider,
            [$this->line($venue, $slots, $startsAt, $resourceId)],
            ['name' => 'Walk-in customer', 'phone' => '+966555000111'],
            'cash',
            $venue->provider->owner,
        );
    }

    protected function assertStatuses(array $urls, int $expected): void
    {
        foreach ($urls as $name => $url) {
            $response = $this->get($url);
            $error = $response->status() === $expected ? '' : mb_substr((string) ($response->exception?->getMessage() ?? strip_tags((string) $response->getContent())), 0, 400);
            $this->assertSame($expected, $response->status(), "[{$name}] GET {$url} returned {$response->status()}: {$error}");
        }
    }
}
