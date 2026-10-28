<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider packages (plans). A subscription gives the features of its plan, each for its own number of
 * days. One plan can be the free plan: every provider without a running paid plan is on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('duration_days');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_free')->default(false); // at most one: the plan of every provider without a paid one
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 40); // App\Enums\PlanFeature
            $table->unsignedInteger('value')->nullable(); // a limit (number of activities, ...)
            $table->unsignedSmallInteger('days')->nullable(); // lasts this many days; null = the whole subscription

            $table->unique(['plan_id', 'feature']);
        });

        Schema::create('provider_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20); // pending | active | rejected | cancelled
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('duration_days');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->string('note', 1000)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status', 'ends_at']);
        });

        // What the subscription actually grants, copied from the plan when it starts: editing a plan
        // later never changes a running subscription. Each feature carries its own end date.
        Schema::create('subscription_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 40);
            $table->unsignedInteger('value')->nullable();
            // dateTime (UTC like everything else): MariaDB refuses two NOT NULL timestamps without defaults
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            $table->index(['provider_id', 'feature', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_features');
        Schema::dropIfExists('provider_subscriptions');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
    }
};
