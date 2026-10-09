<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An activity added to a store that is already live is reviewed by the platform before
 * customers can see it. Existing activities are approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('review_status', 20)->default('approved')->after('is_active'); // approved | pending | rejected
            $table->string('review_note', 500)->nullable()->after('review_status');
            $table->timestamp('reviewed_at')->nullable()->after('review_note');
            $table->index('review_status');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex(['review_status']);
            $table->dropColumn(['review_status', 'review_note', 'reviewed_at']);
        });
    }
};
