<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entertainment venues and what can be booked in them:
 * venue -> activities (billiards, bowling, ...) -> resources (table 3, lane 1, ...).
 * Each resource is booked by time slot; see bookings / booking_slots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('slug')->unique();
            $table->string('summary_ar', 500)->nullable();
            $table->string('summary_en', 500)->nullable();
            $table->longText('description_ar')->nullable();
            $table->longText('description_en')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('audience', 20)->default('all'); // all | families | men | women | kids
            $table->unsignedTinyInteger('min_age')->nullable();
            // none | flexible | moderate | strict
            $table->string('refund_policy', 20)->default('moderate');
            $table->text('refund_policy_note')->nullable();
            // how far ahead customers can book
            $table->unsignedSmallInteger('advance_booking_days')->default(14);
            $table->decimal('min_price', 12, 2)->default(0); // lowest hourly price, denormalised for sorting/filtering
            $table->boolean('is_featured')->default(false)->index();
            // draft | pending_review | published | rejected | suspended
            $table->string('status', 20)->default('draft')->index();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('venue_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10)->default('image'); // image | video | gif
            $table->string('path')->nullable();           // uploaded file
            $table->string('url')->nullable();            // external video url
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Working hours per weekday in the business timezone (config farah.timezone).
        // closes_at <= opens_at means the day runs past midnight (e.g. 16:00 -> 02:00).
        Schema::create('venue_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = Sunday ... 6 = Saturday
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->unique(['venue_id', 'weekday']);
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained(); // activity type: billiards, bowling, gaming, ...
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('description_ar', 1000)->nullable();
            $table->string('description_en', 1000)->nullable();
            $table->decimal('price_per_hour', 12, 2);
            $table->unsignedSmallInteger('slot_minutes')->default(60);  // length of one bookable slot
            $table->unsignedTinyInteger('min_slots')->default(1);       // shortest booking, in slots
            $table->unsignedTinyInteger('max_slots')->default(4);       // longest booking, in slots
            $table->unsignedSmallInteger('capacity')->nullable();       // players per resource, informational
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['sector_id', 'is_active']);
        });

        // The bookable unit: one table, lane, room, track or court. Availability is per resource.
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'venue_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('resources');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('venue_hours');
        Schema::dropIfExists('venue_media');
        Schema::dropIfExists('venues');
    }
};
