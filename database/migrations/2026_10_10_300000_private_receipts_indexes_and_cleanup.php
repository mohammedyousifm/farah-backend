<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * 1. Settlement receipts leave the public disk (they are now served by short-lived signed links).
 * 2. Indexes for the two scheduler queries that scan bookings and orders every five minutes.
 * 3. The unused Wathq columns go: that integration was removed from the product.
 */
return new class extends Migration
{
    public function up(): void
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        foreach (DB::table('settlements')->whereNotNull('receipt_path')->pluck('receipt_path') as $path) {
            if ($public->exists($path)) {
                $private->put($path, $public->get($path));
                $public->delete($path);
            }
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['status', 'ends_at'], 'bookings_status_ends_idx');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['review_prompted_at', 'status'], 'orders_review_prompt_idx');
        });

        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn(['wathq_verified_at', 'wathq_payload']);
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->timestamp('wathq_verified_at')->nullable();
            $table->json('wathq_payload')->nullable();
        });
        Schema::table('orders', fn (Blueprint $table) => $table->dropIndex('orders_review_prompt_idx'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropIndex('bookings_status_ends_idx'));
    }
};
