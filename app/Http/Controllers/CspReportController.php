<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives the browser's Content-Security-Policy reports (AUTH-ADMIN-002, Report-Only step) and writes the
 * useful fields, shortened, to storage/logs/csp-YYYY-MM-DD.log. Nothing from the report is shown anywhere.
 */
class CspReportController
{
    public function __invoke(Request $request): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        $report = is_array($body) ? ($body['csp-report'] ?? $body[0]['body'] ?? $body) : [];

        if (is_array($report) && $report !== []) {
            $pick = fn (string ...$keys) => Str::limit((string) collect($keys)->map(fn ($k) => $report[$k] ?? null)->filter()->first(), 300);

            Log::channel('csp')->info('csp-violation', [
                'directive' => $pick('violated-directive', 'effective-directive', 'effectiveDirective'),
                'blocked' => $pick('blocked-uri', 'blockedURL'),
                'page' => Str::before($pick('document-uri', 'documentURL'), '?'),
                'source' => $pick('source-file', 'sourceFile'),
                'line' => $report['line-number'] ?? $report['lineNumber'] ?? null,
                'sample' => $pick('script-sample', 'sample'),
            ]);
        }

        return response()->noContent();
    }
}
