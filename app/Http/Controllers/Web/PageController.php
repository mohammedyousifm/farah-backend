<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Web\Support\SiteLocale;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/** Static content pages (about, terms, privacy, refund policy), edited in Admin → Content → Pages. */
class PageController extends SiteController
{
    /** Seeded placeholder bodies (ReferenceDataSeeder) are admin instructions, never shown to visitors. */
    private const PLACEHOLDER = '/(Admin\W+Content\W+Pages|لوحة الإدارة\W+المحتوى\W+الصفحات)/u';

    public function __invoke(Request $request, string $slug): View
    {
        $page = Page::where('slug', $slug)->where('is_published', true)->firstOrFail();

        // The body is read in the visitor's language only (no Arabic fallback on the English page);
        // an empty or placeholder body shows a friendly "being updated" note instead.
        $locale = SiteLocale::apply($request);
        $body = self::clean((string) $page->getAttribute('body_'.$locale));
        if (preg_match(self::PLACEHOLDER, strip_tags($body)) && mb_strlen(strip_tags($body)) < 200) {
            $body = '';
        }

        return $this->render($request, 'site.page', ['page' => $page, 'body' => $body]);
    }

    /**
     * The body is rich text written by admins. Keep formatting tags only (no attributes except a
     * link's href to http(s)/mailto/tel); scripts, embeds, event handlers and javascript: links go.
     * A real HTML parser (symfony/html-sanitizer, shipped with Filament), not regexes.
     */
    public static function clean(string $html): string
    {
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->withMaxInputLength(500_000);
        foreach (['p', 'br', 'h2', 'h3', 'h4', 'h5', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'u', 'blockquote', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span', 'div'] as $tag) {
            $config = $config->allowElement($tag);
        }
        $config = $config->allowElement('a', ['href']);

        return trim((new HtmlSanitizer($config))->sanitize($html));
    }
}
