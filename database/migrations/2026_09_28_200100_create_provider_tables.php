<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service providers (individual or establishment), their verification
 * documents, approved sectors, team members and new-sector requests (FR-PRO).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('type', 20); // individual | establishment
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('slug')->unique();
            $table->text('bio_ar')->nullable();
            $table->text('bio_en')->nullable();
            $table->string('logo')->nullable();
            $table->string('cover')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('website')->nullable();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            // Legal identity
            $table->string('national_id', 20)->nullable();      // individual
            $table->string('cr_number', 20)->nullable()->index(); // establishment (Wathq)
            $table->string('legal_name')->nullable();
            $table->string('vat_number', 20)->nullable();

            // Payout details
            $table->string('bank_name')->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('account_holder')->nullable();

            // pending | approved | rejected | suspended
            $table->string('status', 20)->default('pending')->index();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('wathq_verified_at')->nullable();
            $table->json('wathq_payload')->nullable();

            // Internal colour rating seen only by admins: green | yellow | red
            $table->string('internal_rating', 10)->nullable();
            $table->text('internal_notes')->nullable();

            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('provider_sector', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->timestamps();
            $table->unique(['provider_id', 'sector_id']);
        });

        Schema::create('provider_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained()->nullOnDelete();
            // national_id | commercial_register | license | vat_certificate | iban_letter | other
            $table->string('type', 30);
            $table->string('title')->nullable();
            $table->string('number')->nullable();
            $table->string('file_path');
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            // pending | approved | rejected | expired
            $table->string('status', 20)->default('pending')->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expiry_notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('staff'); // owner | manager | staff
            $table->json('permissions')->nullable();       // sub-permissions for staff
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['provider_id', 'user_id']);
        });

        Schema::create('sector_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            // pending | approved | rejected
            $table->string('status', 20)->default('pending')->index();
            $table->text('admin_notes')->nullable();
            $table->foreignId('sector_id')->nullable()->constrained()->nullOnDelete(); // created sector
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sector_requests');
        Schema::dropIfExists('provider_members');
        Schema::dropIfExists('provider_documents');
        Schema::dropIfExists('provider_sector');
        Schema::dropIfExists('providers');
    }
};
