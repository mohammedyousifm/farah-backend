<?php

namespace Tests\Feature;

use App\Models\Sector;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_renders_in_arabic_and_english(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->get('/')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('landing.join', [], 'ar'))
            ->assertSee('/provider/login', false)
            ->assertSee('/provider/register', false)
            ->assertSee(Sector::where('is_active', true)->orderBy('sort_order')->value('name_ar'));

        $this->get('/?lang=en')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('List your venue')
            ->assertSee('/provider/login', false)
            ->assertSee('/provider/register', false)
            ->assertSessionHas('admin_locale', 'en');

        // The choice sticks for the next visit and for the provider auth pages.
        $this->get('/')->assertSee('dir="ltr"', false);
        $this->get('/provider/login')->assertOk()->assertSee('dir="ltr"', false);
    }
}
