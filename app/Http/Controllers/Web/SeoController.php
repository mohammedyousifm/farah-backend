<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/** robots.txt and sitemap.xml for the public website. */
class SeoController extends Controller
{
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /provider',
            'Disallow: /api/',
            'Disallow: /payments/',
            'Disallow: /admin-locale/',
            '',
            'Sitemap: '.url('/sitemap.xml'),
            '',
        ];

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $pages = SiteController::publishedPages();

        $entries = [['url' => url('/').'/', 'lastmod' => null, 'priority' => '1.0']];
        foreach ($pages as $slug => $page) {
            $entries[] = ['url' => url($slug), 'lastmod' => $page->updated_at?->toAtomString(), 'priority' => $slug === 'about' ? '0.6' : '0.3'];
        }
        $entries[] = ['url' => url('contact'), 'lastmod' => null, 'priority' => '0.5'];

        // Every page is served in both languages (?lang=ar|en); list each version with its alternates.
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";
        foreach ($entries as $entry) {
            foreach (['ar', 'en'] as $locale) {
                $xml .= "  <url>\n    <loc>".e($entry['url'].'?lang='.$locale)."</loc>\n";
                foreach (['ar', 'en'] as $alt) {
                    $xml .= '    <xhtml:link rel="alternate" hreflang="'.$alt.'" href="'.e($entry['url'].'?lang='.$alt).'"/>'."\n";
                }
                $xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'.e($entry['url']).'"/>'."\n";
                if ($entry['lastmod']) {
                    $xml .= '    <lastmod>'.$entry['lastmod']."</lastmod>\n";
                }
                $xml .= '    <priority>'.$entry['priority']."</priority>\n  </url>\n";
            }
        }
        $xml .= "</urlset>\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
