<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ComplaintController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Provider;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\TrackingController;
use App\Http\Controllers\Api\V1\VenueController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Farah mobile API v1  (docs/API.md, docs/openapi.yaml)
|--------------------------------------------------------------------------
| Bearer tokens (Sanctum), JSON only, Accept-Language: ar | en.
| Route names are prefixed "api.v1.".
*/

// Payment gateway → platform (signed, idempotent). Outside the throttled group.
Route::post('v1/payments/webhook', PaymentWebhookController::class)->name('api.v1.payments.webhook');

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function () {

    // ---------------------------------------------------------------- Auth
    Route::post('auth/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp')->name('auth.otp.request');
    Route::post('auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp')->name('auth.otp.verify');

    // ------------------------------------------------ Catalog (optional auth)
    Route::get('app/config', [CatalogController::class, 'config'])->name('app.config');
    Route::get('cities', [CatalogController::class, 'cities'])->name('cities');
    Route::get('sectors', [CatalogController::class, 'sectors'])->name('sectors');
    Route::get('categories', [CatalogController::class, 'categories'])->name('categories');
    Route::get('home', [CatalogController::class, 'home'])->name('home');
    Route::get('pages/{slug}', [CatalogController::class, 'page'])->name('pages.show');

    Route::get('venues', [VenueController::class, 'index'])->name('venues.index');
    Route::get('venues/{venue}/reviews', [VenueController::class, 'reviews'])->whereNumber('venue')->name('venues.reviews');
    Route::get('venues/{venue}/similar', [VenueController::class, 'similar'])->whereNumber('venue')->name('venues.similar');
    Route::get('venues/{venue}', [VenueController::class, 'show'])->name('venues.show'); // id or slug
    Route::get('activities/{activity}/availability', [VenueController::class, 'availability'])->whereNumber('activity')->name('activities.availability');
    Route::get('recommendations', [VenueController::class, 'recommendations'])->name('recommendations');
    Route::get('providers/{provider}', [VenueController::class, 'provider'])->whereNumber('provider')->name('providers.show');

    Route::post('track', [TrackingController::class, 'store'])->name('track');

    // ------------------------------------------------------ Signed-in users
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::put('me', [AuthController::class, 'update'])->name('me.update');
        Route::post('me', [AuthController::class, 'update'])->name('me.update.multipart'); // multipart avatar upload
        Route::delete('me', [AuthController::class, 'destroy'])->name('me.destroy');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('me/device-tokens', [AuthController::class, 'storeDeviceToken'])->name('me.device-tokens.store');
        Route::delete('me/device-tokens', [AuthController::class, 'destroyDeviceToken'])->name('me.device-tokens.destroy');

        // Favorites
        Route::get('favorites', [FavoriteController::class, 'index'])->name('favorites.index');
        Route::post('favorites/{venue}', [FavoriteController::class, 'store'])->whereNumber('venue')->name('favorites.store');
        Route::delete('favorites/{venue}', [FavoriteController::class, 'destroy'])->whereNumber('venue')->name('favorites.destroy');

        // Bookings: pick slots → pending order (slots held for the payment window) + payment
        Route::post('bookings', [BookingController::class, 'store'])->middleware('throttle:actions')->name('bookings.store');
        Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::get('bookings/{booking}', [BookingController::class, 'show'])->whereNumber('booking')->name('bookings.show');

        // Orders & payments
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
        Route::post('orders/{order}/pay', [PaymentController::class, 'retry'])->whereNumber('order')->middleware('throttle:actions')->name('orders.pay');
        Route::post('orders/{order}/pay-wallet', [PaymentController::class, 'wallet'])->whereNumber('order')->middleware('throttle:actions')->name('orders.pay-wallet');
        Route::get('wallet', [WalletController::class, 'show'])->name('wallet.show');
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{order}', [InvoiceController::class, 'show'])->whereNumber('order')->name('invoices.show');
        Route::get('orders/{order}/refund-quote', [OrderController::class, 'refundQuote'])->whereNumber('order')->name('orders.refund-quote');
        Route::post('orders/{order}/refund', [OrderController::class, 'refund'])->whereNumber('order')->middleware('throttle:actions')->name('orders.refund');
        Route::get('payments/{reference}', [PaymentController::class, 'show'])->name('payments.show');

        // Reviews & complaints
        Route::post('reviews', [ReviewController::class, 'store'])->middleware('throttle:actions')->name('reviews.store');
        Route::get('complaints', [ComplaintController::class, 'index'])->name('complaints.index');
        Route::post('complaints', [ComplaintController::class, 'store'])->middleware('throttle:actions')->name('complaints.store');
        Route::get('complaints/{complaint}', [ComplaintController::class, 'show'])->whereNumber('complaint')->name('complaints.show');
        Route::post('complaints/{complaint}/replies', [ComplaintController::class, 'reply'])->whereNumber('complaint')->middleware('throttle:actions')->name('complaints.reply');

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        // ---------------------------------------------------- Provider app
        Route::post('provider/register', [Provider\ProfileController::class, 'register'])->name('provider.register');

        Route::prefix('provider')->name('provider.')->middleware('provider')->group(function () {
            Route::get('dashboard', Provider\DashboardController::class)->name('dashboard');

            Route::get('profile', [Provider\ProfileController::class, 'show'])->name('profile.show');
            Route::middleware('provider:profile.manage')->group(function () {
                Route::put('profile', [Provider\ProfileController::class, 'update'])->name('profile.update');
                Route::post('profile', [Provider\ProfileController::class, 'update'])->name('profile.update.multipart');
                Route::get('documents', [Provider\DocumentController::class, 'index'])->name('documents.index');
                Route::post('documents', [Provider\DocumentController::class, 'store'])->name('documents.store');
                Route::post('sector-requests', [Provider\SectorController::class, 'storeRequest'])->name('sector-requests.store');
            });
            Route::get('sectors', [Provider\SectorController::class, 'index'])->name('sectors.index');
            Route::get('sector-requests', [Provider\SectorController::class, 'requests'])->name('sector-requests.index');

            // Venues: working hours, gallery, activities and their resources
            Route::get('venues', [Provider\VenueController::class, 'index'])->name('venues.index');
            Route::get('venues/{venue}', [Provider\VenueController::class, 'show'])->whereNumber('venue')->name('venues.show');
            Route::get('venues/{venue}/hours', [Provider\VenueController::class, 'hours'])->whereNumber('venue')->name('venues.hours.index');
            Route::get('venues/{venue}/media', [Provider\VenueMediaController::class, 'index'])->whereNumber('venue')->name('venues.media.index');
            Route::get('venues/{venue}/activities', [Provider\ActivityController::class, 'index'])->whereNumber('venue')->name('venues.activities.index');
            Route::get('venues/{venue}/activities/{activity}', [Provider\ActivityController::class, 'show'])->whereNumber(['venue', 'activity'])->name('venues.activities.show');
            Route::get('venues/{venue}/activities/{activity}/resources', [Provider\ActivityController::class, 'resources'])->whereNumber(['venue', 'activity'])->name('venues.activities.resources.index');
            // The day at the counter: every slot of every resource with its booking.
            Route::get('venues/{venue}/schedule', [Provider\ScheduleController::class, 'show'])->whereNumber('venue')->name('venues.schedule');

            Route::middleware('provider:venues.manage')->group(function () {
                Route::post('venues', [Provider\VenueController::class, 'store'])->name('venues.store');
                Route::put('venues/{venue}', [Provider\VenueController::class, 'update'])->whereNumber('venue')->name('venues.update');
                Route::post('venues/{venue}', [Provider\VenueController::class, 'update'])->whereNumber('venue')->name('venues.update.multipart');
                Route::delete('venues/{venue}', [Provider\VenueController::class, 'destroy'])->whereNumber('venue')->name('venues.destroy');
                Route::post('venues/{venue}/submit', [Provider\VenueController::class, 'submit'])->whereNumber('venue')->name('venues.submit');
                Route::put('venues/{venue}/hours', [Provider\VenueController::class, 'updateHours'])->whereNumber('venue')->name('venues.hours.update');

                Route::post('venues/{venue}/media', [Provider\VenueMediaController::class, 'store'])->whereNumber('venue')->name('venues.media.store');
                Route::put('venues/{venue}/media/order', [Provider\VenueMediaController::class, 'reorder'])->whereNumber('venue')->name('venues.media.reorder');
                Route::delete('venues/{venue}/media/{media}', [Provider\VenueMediaController::class, 'destroy'])->whereNumber(['venue', 'media'])->name('venues.media.destroy');

                Route::post('venues/{venue}/activities', [Provider\ActivityController::class, 'store'])->whereNumber('venue')->name('venues.activities.store');
                Route::put('venues/{venue}/activities/{activity}', [Provider\ActivityController::class, 'update'])->whereNumber(['venue', 'activity'])->name('venues.activities.update');
                Route::delete('venues/{venue}/activities/{activity}', [Provider\ActivityController::class, 'destroy'])->whereNumber(['venue', 'activity'])->name('venues.activities.destroy');

                Route::post('venues/{venue}/activities/{activity}/resources', [Provider\ActivityController::class, 'storeResource'])->whereNumber(['venue', 'activity'])->name('venues.activities.resources.store');
                Route::put('venues/{venue}/activities/{activity}/resources/{resource}', [Provider\ActivityController::class, 'updateResource'])->whereNumber(['venue', 'activity', 'resource'])->name('venues.activities.resources.update');
                Route::delete('venues/{venue}/activities/{activity}/resources/{resource}', [Provider\ActivityController::class, 'destroyResource'])->whereNumber(['venue', 'activity', 'resource'])->name('venues.activities.resources.destroy');

                // Take slots out of sale (maintenance, private use) and put them back.
                Route::post('venues/{venue}/blocks', [Provider\ScheduleController::class, 'block'])->whereNumber('venue')->name('venues.blocks.store');
                Route::delete('venues/{venue}/blocks/{slot}', [Provider\ScheduleController::class, 'unblock'])->whereNumber(['venue', 'slot'])->name('venues.blocks.destroy');
            });

            // Orders, counter bookings & arrival check-in
            Route::get('orders', [Provider\OrderController::class, 'index'])->middleware('provider:orders.view')->name('orders.index');
            Route::get('orders/{order}', [Provider\OrderController::class, 'show'])->whereNumber('order')->middleware('provider:orders.view')->name('orders.show');
            Route::post('orders/offline', [Provider\OrderController::class, 'offline'])->middleware('provider:orders.offline')->name('orders.offline');
            Route::post('bookings/check-in', [Provider\OrderController::class, 'checkIn'])->middleware('provider:bookings.checkin')->name('bookings.check-in');

            // Team
            Route::middleware('provider:team.manage')->group(function () {
                Route::get('members', [Provider\TeamController::class, 'index'])->name('members.index');
                Route::post('members', [Provider\TeamController::class, 'store'])->name('members.store');
                Route::put('members/{member}', [Provider\TeamController::class, 'update'])->whereNumber('member')->name('members.update');
                Route::delete('members/{member}', [Provider\TeamController::class, 'destroy'])->whereNumber('member')->name('members.destroy');
            });

            // Finance
            Route::middleware('provider:finance.view')->prefix('finance')->name('finance.')->group(function () {
                Route::get('balances', [Provider\FinanceController::class, 'balances'])->name('balances');
                Route::get('ledger', [Provider\FinanceController::class, 'ledger'])->name('ledger');
                Route::get('settlements', [Provider\FinanceController::class, 'settlements'])->name('settlements.index');
                Route::get('settlements/{settlement}', [Provider\FinanceController::class, 'settlement'])->whereNumber('settlement')->name('settlements.show');
            });

            // Reviews
            Route::get('reviews', [Provider\ReviewController::class, 'index'])->name('reviews.index');
            Route::post('reviews/{review}/reply', [Provider\ReviewController::class, 'reply'])->whereNumber('review')->middleware('provider:reviews.reply')->name('reviews.reply');
        });
    });
});
