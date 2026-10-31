<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents are no longer asked of every store at sign-up (the CR number, address and IBAN are typed in the form).
 * While reviewing, the platform asks a store for a specific document (e.g. the license an activity needs); the
 * store sees exactly that request. Open = no document answers it yet (or the one sent was rejected).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('note', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('provider_documents')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'fulfilled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_document_requests');
    }
};
