<?php

namespace Tests\Feature\Landing;

use App\Models\Page;
use App\Models\Setting;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Public website: landing SEO, static pages, contact, sitemap, robots and branded error pages. */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Cache::flush();
    }

    public function test_landing_has_seo_tags_and_accessible_structure(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'/?lang=ar">', $html);
        $this->assertStringContainsString('hreflang="en" href="'.url('/').'/?lang=en"', $html);
        $this->assertStringContainsString('hreflang="x-default"', $html);
        $this->assertStringContainsString('property="og:image" content="'.asset('images/og-share.png').'"', $html);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
        $this->assertStringContainsString('"@type":"Organization"', $html);
        $this->assertStringContainsString('aria-controls="mnav"', $html);
        $this->assertStringContainsString(__('landing.hero.stores', [], 'ar'), $html);
        $this->assertStringContainsString('href="'.url('/terms').'"', $html);
        $this->assertStringContainsString('href="'.url('/contact').'"', $html);
        $this->assertDoesNotMatchRegularExpression('/<img(?![^>]*\balt=)[^>]*>/', $html, 'every image needs alt text');
        $this->assertStringNotContainsString('landing.', strip_tags($html), 'no untranslated keys');

        foreach (['images/og-share.png', 'images/logo-mark-sm.png', 'images/apple-touch-icon.png', 'images/favicon.png'] as $file) {
            $this->assertFileExists(public_path($file));
        }
    }

    public function test_english_landing_has_no_arabic_except_brand(): void
    {
        $html = $this->get('/?lang=en')->assertOk()->getContent();
        $body = preg_replace('#<(script|style)\b.*?</\1>#s', '', substr($html, strpos($html, '<body')));
        $text = str_replace(['فرة', 'العربية'], '', html_entity_decode(strip_tags($body)));

        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $text);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'/?lang=en">', $html);
        $this->assertStringContainsString('content="en_US"', $html);
    }

    public function test_landing_presents_hourly_venue_booking_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $html = $this->get('/?lang='.$locale)->assertOk()
                ->assertSee(__('landing.hero.title', [], $locale))
                ->assertSee(__('landing.about.title', [], $locale))
                ->assertSee(__('landing.contact.title', [], $locale))
                ->getContent();

            // The hero is the real app: every screen of the tour exists and each flow step has at least one.
            preg_match_all('#images/app-tour/([a-z0-9-]+)\.webp#', $html, $frames);
            $this->assertCount(13, $frames[1]);
            foreach ($frames[1] as $frame) {
                $this->assertFileExists(public_path('images/app-tour/'.$frame.'.webp'));
            }
            for ($step = 0; $step < 6; $step++) {
                $this->assertStringContainsString('data-step="'.$step.'" ', $html);
            }

            // Nothing is left of the old "sell tickets for your events" pitch.
            $text = strip_tags(preg_replace('#<(script|style)\b.*?</\1>#s', '', $html));
            $this->assertDoesNotMatchRegularExpression('/\b(events?|tickets?|e-tickets?)\b/i', $text);
            $this->assertDoesNotMatchRegularExpression('/فعالي|تذكرة|تذاكر/u', $text);
        }
    }

    public function test_static_pages_render_in_both_languages(): void
    {
        foreach (['about', 'terms', 'privacy', 'refund-policy'] as $slug) {
            $page = Page::where('slug', $slug)->firstOrFail();

            $this->get('/'.$slug.'?lang=ar')->assertOk()
                ->assertSee('dir="rtl"', false)
                ->assertSee($page->title_ar)
                ->assertSee('href="'.url('/'.$slug).'?lang=en"', false); // language switch stays on the page

            $this->get('/'.$slug.'?lang=en')->assertOk()
                ->assertSee('dir="ltr"', false)
                ->assertSee($page->title_en)
                ->assertSee('<link rel="canonical" href="'.url('/'.$slug).'?lang=en">', false)
                // The seeded admin instruction is never shown to visitors.
                ->assertDontSee('Edit this content from Admin')
                ->assertSee(__('site.page.empty', [], 'en'));
        }
    }

    public function test_page_body_is_sanitised_and_not_mixed_across_languages(): void
    {
        Page::where('slug', 'terms')->update([
            'body_ar' => '<h2>البند الأول</h2><p onclick="alert(1)">نص عربي</p><script>alert(1)</script>',
            'body_en' => null,
        ]);

        $this->get('/terms?lang=ar')->assertOk()
            ->assertSee('<h2>البند الأول</h2>', false)
            ->assertSee('نص عربي')
            ->assertDontSee('alert(1)', false);

        // No English body: a friendly note instead of the Arabic text.
        $this->get('/terms?lang=en')->assertOk()
            ->assertDontSee('نص عربي')
            ->assertSee(__('site.page.empty', [], 'en'));
    }

    public function test_unpublished_page_is_404_and_left_out_of_footer_and_sitemap(): void
    {
        Page::where('slug', 'privacy')->update(['is_published' => false]);

        $this->get('/privacy')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('href="'.url('/privacy').'"', false);
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/privacy?lang=');
        $this->get('/unknown-slug')->assertNotFound();
    }

    public function test_contact_page_uses_support_settings(): void
    {
        $this->get('/contact')->assertOk()
            ->assertSee('tel:'.config('farah.defaults.support_phone'), false)
            ->assertSee('mailto:'.config('farah.defaults.support_email'), false)
            ->assertDontSee('<form', false);

        Setting::put('support_email', 'help@farah.test');
        Setting::put('support_phone', '+966 55 123 4567');

        $this->get('/contact?lang=en')->assertOk()
            ->assertSee('Contact us')
            ->assertSee('mailto:help@farah.test', false)
            ->assertSee('tel:+966551234567', false)
            ->assertSee('https://wa.me/966551234567', false);
    }

    public function test_sitemap_lists_public_pages_in_both_languages(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringStartsWith('application/xml', $response->headers->get('Content-Type'));

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $locs = array_map('strval', $xml->xpath('//*[local-name()="loc"]'));

        foreach (['/?lang=ar', '/?lang=en', '/about?lang=en', '/terms?lang=ar', '/privacy?lang=ar', '/refund-policy?lang=en', '/contact?lang=ar'] as $path) {
            $this->assertContains(url('/').$path, $locs);
        }
    }

    public function test_robots_txt_blocks_dashboards_and_points_to_sitemap(): void
    {
        $this->get('/robots.txt')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /admin')
            ->assertSee('Disallow: /provider')
            ->assertSee('Disallow: /api/')
            ->assertSee('Sitemap: '.url('/sitemap.xml'));
    }

    public function test_404_page_is_branded_and_follows_the_session_locale(): void
    {
        $this->get('/no-such-page')->assertNotFound()
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('site.errors.404.title', [], 'ar'))
            ->assertSee('images/logo-mark-sm.png', false);

        $this->get('/?lang=en');
        $this->get('/still-missing')->assertNotFound()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Page not found')
            ->assertSee('Back to home')
            ->assertDontSee(__('site.errors.home', [], 'ar'));

        // The API keeps its JSON errors.
        $this->getJson('/api/v1/no-such-endpoint')->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_500_and_503_pages_render_branded_in_both_languages(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/__boom', fn () => throw new \RuntimeException('secret failure detail'));

        $this->get('/__boom')->assertStatus(500)
            ->assertSee(__('site.errors.500.title', [], 'ar'))
            ->assertDontSee('secret failure detail');

        $this->get('/__boom?lang=en')->assertStatus(500)->assertSee('Something went wrong');

        Route::middleware('web')->get('/__down', fn () => abort(503));

        $this->get('/__down?lang=ar')->assertStatus(503)
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('site.errors.503.title', [], 'ar'))
            ->assertSee(__('site.errors.retry', [], 'ar'));

        // Without ?lang the session choice (set above) is used.
        $this->get('/?lang=en');
        $this->get('/__down')->assertStatus(503)->assertSee('Back soon')->assertSee('Try again');
    }
}
