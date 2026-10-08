<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders, payments and the financial engine: versioned commission rules,
 * an append-only ledger, settlements, refunds and disputes (FR-ORD, FR-PAY, BR-004..007).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // global | sector | category | provider  (most specific wins)
            $table->string('scope', 20)->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            // percentage | fixed | percentage_plus_fixed
            $table->string('type', 30)->default('percentage');
            $table->decimal('rate', 5, 2)->default(0);        // percent
            $table->decimal('fixed_amount', 12, 2)->default(0); // SAR per order
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scope', 'scope_id', 'effective_from']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->string('gateway', 30);
            $table->string('gateway_payment_id')->nullable()->index();
            $table->string('method', 30)->nullable(); // mada | visa | mastercard | applepay | stcpay
            // initiated | paid | failed | refunded | partially_refunded
            $table->string('status', 20)->default('initiated')->index();
            $table->string('failure_reason')->nullable();
            $table->string('checkout_url', 500)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 30);
            $table->string('event_id');
            $table->string('type')->nullable();
            $table->json('payload');
            $table->boolean('signature_valid')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'event_id']); // idempotency guard
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->constrained();
            $table->foreignId('venue_id')->constrained();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 10)->default('online'); // online | offline
            // pending_payment | confirmed | cancelled | refunded | partially_refunded | completed | expired
            $table->string('status', 25)->default('pending_payment')->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('customer_email')->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            // commission snapshot taken at confirmation time
            $table->foreignId('commission_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->decimal('commission_fixed', 12, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->decimal('provider_net', 12, 2)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->string('offline_payment_method', 30)->nullable(); // cash | transfer | pos
            $table->text('notes')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('accounting_ref')->nullable();
            $table->timestamp('accounting_synced_at')->nullable();
            $table->timestamps();
            $table->index(['provider_id', 'status']);
            $table->index('created_at');
        });

        // One reserved resource for a continuous run of slots. An order holds one or more bookings.
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // shown as QR, scanned on arrival
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->constrained();
            $table->foreignId('venue_id')->constrained();
            $table->foreignId('activity_id')->constrained();
            $table->foreignId('resource_id')->constrained();
            $table->string('name');               // snapshot: "Billiards - Table 3"
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('slot_minutes');
            $table->unsignedTinyInteger('slots');
            $table->decimal('unit_price', 12, 2); // per slot
            $table->decimal('total', 12, 2);
            // pending | confirmed | checked_in | completed | cancelled | expired
            $table->string('status', 15)->default('pending')->index();
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['resource_id', 'starts_at']);
            $table->index(['user_id', 'starts_at']);
            $table->index(['venue_id', 'starts_at']);
        });

        // Exclusivity: one row per held slot. The unique key makes double booking impossible;
        // rows are deleted when a booking is cancelled, expires or is refunded.
        // booking_id null = slot blocked by the provider (maintenance, private use).
        Schema::create('booking_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('block_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['resource_id', 'starts_at']);
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('provider_id')->constrained();
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->decimal('refunds_amount', 12, 2)->default(0);
            $table->decimal('adjustments_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            // draft | pending_approval | approved | paid | rejected
            $table->string('status', 20)->default('draft')->index();
            $table->json('bank_snapshot')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('receipt_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 10); // full | partial
            $table->decimal('amount', 12, 2);
            $table->decimal('commission_reversed', 12, 2)->default(0);
            $table->decimal('provider_debit', 12, 2)->default(0);
            $table->json('items')->nullable(); // [{booking_id}]
            $table->text('reason')->nullable();
            // requested | approved | rejected | processed | failed
            $table->string('status', 20)->default('requested')->index();
            $table->text('admin_notes')->nullable();
            $table->string('gateway_refund_id')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            // provider | platform
            $table->string('account', 10)->index();
            $table->foreignId('provider_id')->nullable()->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->foreignId('refund_id')->nullable()->constrained();
            $table->foreignId('settlement_id')->nullable()->constrained();
            // sale | commission | refund | commission_reversal | payout | adjustment
            $table->string('type', 25)->index();
            // signed: + credit, - debit
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            // funds are held until this moment, then become available for settlement
            $table->timestamp('available_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('provider_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason');
            $table->text('details')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            // open | under_review | resolved_customer | resolved_provider | closed
            $table->string('status', 25)->default('open')->index();
            $table->text('resolution')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('booking_slots');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('commission_rules');
    }
};
