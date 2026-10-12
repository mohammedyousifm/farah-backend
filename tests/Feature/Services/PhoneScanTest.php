<?php

namespace Tests\Feature\Services;

use App\Enums\BookingStatus;
use App\Filament\Provider\Pages\CheckIn;
use App\Support\PhoneScanner;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** The provider links a phone to the check-in screen and scans booking codes with it. */
class PhoneScanTest extends ServiceTestCase
{
    public function test_phone_scans_and_the_computer_shows_the_result(): void
    {
        $provider = $this->provider();
        $venue = $this->venue($provider);
        $booking = $this->paidOrder($venue, 1, null, $this->slot(1))->bookings->first();

        $this->actingAs($provider->owner, 'provider');
        Filament::setCurrentPanel(Filament::getPanel('provider'));
        Filament::setTenant($provider);

        // Computer: "use your phone" shows a QR with a private link.
        $page = Livewire::test(CheckIn::class)->call('connectPhone')->assertSee(__('provider.checkin.phone_scan_me'));
        $token = $page->get('phoneToken');
        $this->assertNotNull(PhoneScanner::find($token));

        // Phone: a different device with no login at all; the page opens and scans the customer's code.
        auth()->forgetGuards();
        $this->get("/scan/{$token}")->assertOk()->assertSee(__('provider.checkin.phone_title'));
        $this->postJson("/scan/{$token}", ['code' => $booking->code])->assertOk()->assertJson(['ok' => true, 'code' => $booking->code]);
        $this->assertSame(BookingStatus::CheckedIn, $booking->fresh()->status);

        // The same code again is refused, and both attempts wait for the computer screen.
        $this->postJson("/scan/{$token}", ['code' => $booking->code])->assertOk()->assertJson(['ok' => false]);

        $this->actingAs($provider->owner, 'provider');
        $page->call('pollPhone')->assertSee($booking->code)->assertSet('result.ok', false);
        $this->assertCount(2, $page->get('recent'));
        $this->assertSame([], PhoneScanner::pull($token));

        // Signing out of the dashboard kills the link too (checked on a second link, so this one stays for the next step).
        $other = PhoneScanner::connect($provider, $provider->owner);
        session(['farah.phone_scan' => $other]);
        auth('provider')->logout();
        $this->assertNull(PhoneScanner::find($other));
        $this->actingAs($provider->owner, 'provider');

        // Disconnecting kills the link at once.
        $page->call('disconnectPhone')->assertSet('phoneToken', null);
        $this->get("/scan/{$token}")->assertNotFound();
        $this->postJson("/scan/{$token}", ['code' => $booking->code])->assertNotFound();
    }

    public function test_a_guessed_link_or_another_providers_code_gets_nothing(): void
    {
        $provider = $this->provider();
        $other = $this->provider(null, 'Other');
        $booking = $this->paidOrder($this->venue($other), 1, null, $this->slot(1))->bookings->first();

        $this->get('/scan/'.str_repeat('a', 48))->assertNotFound();

        $token = PhoneScanner::connect($provider, $provider->owner);
        $this->postJson("/scan/{$token}", ['code' => $booking->code])->assertOk()->assertJson(['ok' => false]);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        // A member removed from the provider loses the link too.
        $provider->members()->where('user_id', $provider->owner_id)->update(['is_active' => false]);
        $this->get("/scan/{$token}")->assertNotFound();
    }
}
