<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gzips text responses (pages, JSON) when the client accepts it. A web server that already
 * compresses sets Content-Encoding itself after PHP, so this only fills the gap where none does.
 *
 * Runs on the RequestHandled event (see AppServiceProvider), not as middleware: Livewire adds its
 * scripts to the finished page on that same event and must see the page before it is compressed.
 */
class CompressResponse
{
    private const MIN_BYTES = 1024;

    public static function compress(Request $request, Response $response): Response
    {
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse
            || ! function_exists('gzencode')
            || ! str_contains((string) $request->headers->get('Accept-Encoding'), 'gzip')
            || $response->headers->has('Content-Encoding')) {
            return $response;
        }

        $type = (string) $response->headers->get('Content-Type');
        $content = $response->getContent();

        if ($content === false || strlen($content) < self::MIN_BYTES || ! preg_match('#^(text/|application/(json|javascript|xml))#', $type)) {
            return $response;
        }

        $response->setContent(gzencode($content, 5));
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Vary', 'Accept-Encoding', false);
        $response->headers->set('Content-Length', (string) strlen($response->getContent()));

        return $response;
    }
}
