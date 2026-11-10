<?php

use App\Http\Controllers\CspReportController;
use App\Http\Controllers\FakePaymentController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PhoneScanController;
use App\Http\Controllers\PrivateFileController;
use App\Http\Controllers\Web\ContactController;
use App\Http\Controllers\Web\PageController;
use App\Http\Controllers\Web\PlaceController;
use App\Http\Controllers\Web\SeoController;
use App\Http\Controllers\Web\SiteController;
use App\Http\Middleware\SetAdminLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// --- Landing page --- (public website: landing, static pages, contact, SEO files; ?lang=en|ar)
// Browsers post Content-Security-Policy reports here (Report-Only policy of the dashboards).
Route::post('/csp-report', CspReportController::class)->middleware('throttle:60,1')->name('csp.report');
Route::get('/', LandingController::class)->name('landing');
Route::get('/contact', ContactController::class)->name('site.contact');
Route::get('/places/{slug}', PlaceController::class)->name('site.place'); // stores whose plan includes search-engine visibility
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('site.robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('site.sitemap');
Route::get('/.well-known/security.txt', [SeoController::class, 'security'])->name('site.security');
Route::get('/{slug}', PageController::class)
    ->whereIn('slug', SiteController::PAGE_SLUGS)
    ->name('site.page');
// --- end landing page ---

// --- Phone scanner --- (provider check-in: a phone scans booking codes for the screen on the computer)
Route::get('/scan/{token}', [PhoneScanController::class, 'show'])->middleware('throttle:60,1')->name('provider.phone-scan');
Route::post('/scan/{token}', [PhoneScanController::class, 'scan'])->middleware('throttle:120,1')->name('provider.phone-scan.scan');
// --- end phone scanner ---

// --- Invoice PDF --- (customer app: opened in the phone's browser through a signed 15-minute link)
Route::get('/invoices/{order}/pdf', InvoicePdfController::class)->whereNumber('order')->middleware(['signed', 'throttle:30,1'])->name('invoices.pdf');
// --- end invoice PDF ---

// --- Private files --- (dashboards: provider documents and settlement receipts, checked on every request)
Route::get('/files/documents/{document}', [PrivateFileController::class, 'document'])->whereNumber('document')->middleware('throttle:120,1')->name('files.document');
Route::get('/files/receipts/{settlement}', [PrivateFileController::class, 'receipt'])->whereNumber('settlement')->middleware('throttle:120,1')->name('files.receipt');
// Signed, short-lived links (API file_url, upload previews); the path is a query value, see AppServiceProvider.
Route::get('/private-file', [PrivateFileController::class, 'signed'])->middleware(['signed', 'throttle:120,1'])->name('files.private');
// --- end private files ---

// --- Admin locale --- (admin workstream; panel language switch kept in the session)
Route::get('/admin-locale/{locale}', function (Request $request, string $locale) {
    if (in_array($locale, SetAdminLocale::LOCALES, true)) {
        session(['admin_locale' => $locale]);
    }

    // Back to the page the switch was clicked on, but never to another site (the Referer is sent by the browser).
    $back = (string) $request->headers->get('referer');

    return redirect(parse_url($back, PHP_URL_HOST) === $request->getHost() ? $back : '/admin');
})->name('admin.locale');
// --- end admin locale ---

// --- Fake payment gateway --- (API workstream; sandbox checkout page for PAYMENT_DRIVER=fake)
Route::get('/payments/fake/{reference}', [FakePaymentController::class, 'show'])->name('payments.fake');
Route::post('/payments/fake/{reference}', [FakePaymentController::class, 'complete'])->name('payments.fake.complete');
// --- end fake payment gateway ---
