<?php

use App\Http\Controllers\FakePaymentController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\Web\ContactController;
use App\Http\Controllers\Web\PageController;
use App\Http\Controllers\Web\SeoController;
use App\Http\Controllers\Web\SiteController;
use App\Http\Middleware\SetAdminLocale;
use Illuminate\Support\Facades\Route;

// --- Landing page --- (public website: landing, static pages, contact, SEO files; ?lang=en|ar)
Route::get('/', LandingController::class)->name('landing');
Route::get('/contact', ContactController::class)->name('site.contact');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('site.robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('site.sitemap');
Route::get('/{slug}', PageController::class)
    ->whereIn('slug', SiteController::PAGE_SLUGS)
    ->name('site.page');
// --- end landing page ---

// --- Admin locale --- (admin workstream; panel language switch kept in the session)
Route::get('/admin-locale/{locale}', function (string $locale) {
    if (in_array($locale, SetAdminLocale::LOCALES, true)) {
        session(['admin_locale' => $locale]);
    }

    return redirect()->back(fallback: '/admin');
})->name('admin.locale');
// --- end admin locale ---

// --- Fake payment gateway --- (API workstream; sandbox checkout page for PAYMENT_DRIVER=fake)
Route::get('/payments/fake/{reference}', [FakePaymentController::class, 'show'])->name('payments.fake');
Route::post('/payments/fake/{reference}', [FakePaymentController::class, 'complete'])->name('payments.fake.complete');
// --- end fake payment gateway ---
