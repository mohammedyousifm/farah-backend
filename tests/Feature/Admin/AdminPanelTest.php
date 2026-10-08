<?php

namespace Tests\Feature\Admin;

use App\Enums\VenueStatus;
use App\Filament\Pages\Reports;
use App\Filament\Resources\CommissionRules\Pages\ManageCommissionRules;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Filament\Resources\Venues\Pages\ViewVenue;
use App\Models\CommissionRule;
use App\Services\ReportService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Smoke test for the admin panel: every resource list/create page and every
 * custom page renders for a super admin; non-admins are kept out.
 *
 * Run with: DB_DATABASE=farah_test_admin php artisan test tests/Feature/Admin
 */
class AdminPanelTest extends AdminTestCase
{
    /** @return array<int, string> */
    private function panelUrls(): array
    {
        $panel = Filament::getPanel('admin');
        $urls = [];

        foreach ($panel->getResources() as $resource) {
            foreach ($resource::getPages() as $name => $page) {
                if (in_array($name, ['index', 'create'], true)) {
                    $urls[] = $resource::getUrl($name);
                }
            }
        }
        foreach ($panel->getPages() as $page) {
            $urls[] = $page::getUrl();
        }

        return array_values(array_unique($urls));
    }

    public function test_super_admin_can_open_every_page(): void
    {
        $this->actingAs($this->superAdmin());

        $urls = $this->panelUrls();

        foreach ($urls as $url) {
            $response = $this->get($url);
            $error = $response->status() === 200 ? '' : mb_substr(strip_tags((string) ($response->exception?->getMessage() ?? $response->getContent())), 0, 400);
            $this->assertSame(200, $response->status(), "GET {$url} returned {$response->status()}: {$error}");
        }

        $this->assertGreaterThan(25, count($urls));
        $this->assertContains(url('/admin/venues'), $urls);
        $this->assertContains(url('/admin/bookings'), $urls);
    }

    public function test_english_locale_renders(): void
    {
        $this->actingAs($this->superAdmin())->withSession(['admin_locale' => 'en']);

        foreach ($this->panelUrls() as $url) {
            $this->get($url)->assertOk();
        }
    }

    /** With data: every list page, every view/edit page of the first record, and every report (plus exports). */
    public function test_record_pages_and_reports_render_with_data(): void
    {
        $this->world();
        $this->actingAs($this->superAdmin());

        $checked = 0;
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $pages = $resource::getPages();
            $this->get($resource::getUrl('index'))->assertOk();

            $record = $resource::getEloquentQuery()->first();
            if (! $record) {
                continue;
            }
            foreach (['view', 'edit'] as $name) {
                if (! isset($pages[$name]) || ! $resource::can($name === 'view' ? 'view' : 'update', $record)) {
                    continue;
                }
                $url = $resource::getUrl($name, ['record' => $record]);
                $response = $this->get($url);
                $error = $response->status() === 200 ? '' : mb_substr((string) ($response->exception?->getMessage() ?? ''), 0, 400);
                $this->assertSame(200, $response->status(), "GET {$url} returned {$response->status()}: {$error}");
                $checked++;
            }
        }
        $this->assertGreaterThan(10, $checked);

        foreach (ReportService::REPORTS as $key) {
            $this->get(Reports::getUrl(['report' => $key]))->assertOk()->assertDontSee('admin.reports.');
        }

        $this->usePanel();
        Livewire::test(Reports::class)->callAction('export_xlsx')->assertFileDownloaded();
        Livewire::test(Reports::class)->callAction('export_pdf')->assertFileDownloaded();
    }

    /** A few workflow actions go through their services end to end. */
    public function test_workflow_actions_call_services(): void
    {
        $this->world();
        $this->actingAs($this->superAdmin());
        $this->usePanel();

        Livewire::test(ViewVenue::class, ['record' => $this->pendingVenue->getRouteKey()])
            ->callAction('approve')
            ->assertNotified();
        $this->assertSame(VenueStatus::Published, $this->pendingVenue->fresh()->status);

        $rule = CommissionRule::whereNull('effective_to')->where('is_active', true)->firstOrFail();
        Livewire::test(ManageCommissionRules::class)
            ->callTableAction('new_version', $rule, ['type' => 'percentage', 'rate' => 12.5, 'fixed_amount' => 0, 'effective_from' => now()->toDateTimeString()])
            ->assertHasNoTableActionErrors();
        $this->assertNotNull($rule->fresh()->effective_to);
        $this->assertSame($rule->version + 1, CommissionRule::latest('id')->first()->version);

        Livewire::test(ListSettlements::class)
            ->callAction('generate_all', ['until' => now()->toDateString()])
            ->assertNotified();
    }

    public function test_locale_switch_persists_in_session(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get(route('admin.locale', 'en'))->assertRedirect()->assertSessionHas('admin_locale', 'en');
        $this->get('/admin')->assertOk()->assertSee('Dashboard');
        $this->get(route('admin.locale', 'ar'));
        $this->get('/admin')->assertOk()->assertSee('dir="rtl"', false);
    }

    public function test_customer_is_forbidden(): void
    {
        $this->actingAs($this->customer('+966500000001'))->get('/admin')->assertForbidden();
    }

    public function test_limited_role_cannot_open_other_modules(): void
    {
        $this->actingAs($this->superAdmin('content'));

        $this->get('/admin/home-sections')->assertOk();
        $this->get('/admin/settlements')->assertForbidden();
        $this->get('/admin/settings')->assertForbidden();
    }
}
