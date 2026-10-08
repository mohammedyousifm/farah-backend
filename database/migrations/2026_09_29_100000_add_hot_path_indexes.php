<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes for the hottest filters / sorts:
 *  - venues: customer listing (status + featured), price sort;
 *  - orders: expiry job (status + expires_at), "my orders" (user + date);
 *  - ledger_entries: provider balances and settlement generation;
 *  - refunds / reviews: per-order and per-venue lookups by status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->index(['status', 'is_featured'], 'venues_status_featured_idx');
            $table->index('min_price', 'venues_min_price_idx');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'expires_at'], 'orders_status_expires_idx');
            $table->index(['user_id', 'created_at'], 'orders_user_created_idx');
            $table->index(['venue_id', 'status'], 'orders_venue_status_idx');
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->index(['provider_id', 'account', 'settlement_id', 'available_at'], 'ledger_provider_balance_idx');
            $table->index(['refund_id', 'account'], 'ledger_refund_idx');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->index(['order_id', 'status'], 'refunds_order_status_idx');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->index(['venue_id', 'status', 'created_at'], 'reviews_venue_status_idx');
            $table->index(['provider_id', 'status'], 'reviews_provider_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex('reviews_provider_status_idx');
            $table->dropIndex('reviews_venue_status_idx');
        });
        Schema::table('refunds', fn (Blueprint $table) => $table->dropIndex('refunds_order_status_idx'));
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndex('ledger_refund_idx');
            $table->dropIndex('ledger_provider_balance_idx');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_venue_status_idx');
            $table->dropIndex('orders_user_created_idx');
            $table->dropIndex('orders_status_expires_idx');
        });
        Schema::table('venues', function (Blueprint $table) {
            $table->dropIndex('venues_min_price_idx');
            $table->dropIndex('venues_status_featured_idx');
        });
    }
};
