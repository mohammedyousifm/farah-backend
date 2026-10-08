<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews, complaints, home-screen content, notifications, settings,
 * audit trail, behavioural events and rule-based recommendations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->string('status', 15)->default('published')->index(); // published | hidden
            $table->text('provider_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'order_id']);
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('complaint'); // complaint | feedback | suggestion
            $table->string('subject');
            $table->text('body');
            $table->string('priority', 10)->default('normal'); // low | normal | high | urgent
            // open | in_progress | resolved | closed
            $table->string('status', 20)->default('open')->index();
            $table->text('resolution')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('complaint_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false); // internal admin note
            $table->timestamps();
        });

        Schema::create('home_sections', function (Blueprint $table) {
            $table->id();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            // banner | venues | categories | sectors | providers
            $table->string('type', 20);
            $table->string('layout', 20)->default('carousel'); // carousel | grid | list | hero
            // manual | featured | latest | upcoming | top_rated | popular | category | sector | recommended
            $table->string('source', 20)->default('manual');
            $table->json('source_params')->nullable();
            $table->unsignedSmallInteger('max_items')->default(10);
            $table->boolean('is_active')->default(true);
            $table->dateTime('starts_at')->nullable(); // scheduling
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('home_section_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('home_section_id')->constrained()->cascadeOnDelete();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->string('media_type', 10)->nullable(); // image | video | gif
            $table->string('media_path')->nullable();
            $table->string('media_url')->nullable();
            // venue | category | sector | provider | url | none
            $table->string('link_type', 15)->default('none');
            $table->string('link_value')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title_ar');
            $table->string('title_en');
            $table->longText('body_ar')->nullable();
            $table->longText('body_en')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 30)->default('general');
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50)->index();
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('behavior_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 100)->nullable()->index();
            // view_venue | search | favorite | start_booking | purchase | share | view_category
            $table->string('name', 30)->index();
            $table->nullableMorphs('subject');
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('recommendation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // same_category | same_sector | popular_in_city | trending | top_rated | favorites_similar | upcoming
            $table->string('strategy', 30);
            $table->unsignedSmallInteger('weight')->default(10);
            $table->json('params')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 500);
            $table->string('platform', 10); // ios | android | web
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('push_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('body_ar');
            $table->text('body_en')->nullable();
            $table->string('audience', 20)->default('all'); // all | customers | providers
            $table->string('link_type', 15)->default('none');
            $table->string('link_value')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->string('status', 15)->default('draft'); // draft | scheduled | sent
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('service', 20)->index(); // wathq | payment | sms | accounting | push | maps
            $table->string('action', 50);
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->boolean('success')->default(true);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'notifications', 'integration_logs', 'push_campaigns', 'device_tokens',
            'recommendation_rules', 'behavior_events', 'audit_logs', 'settings', 'pages',
            'home_section_items', 'home_sections', 'complaint_replies', 'complaints', 'reviews',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
