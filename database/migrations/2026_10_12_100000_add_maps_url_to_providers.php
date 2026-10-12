<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Providers are establishments only, and each gives a Google Maps link to its place. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('maps_url', 500)->nullable()->after('website');
        });

        DB::table('providers')->where('type', 'individual')->update(['type' => 'establishment']);
        DB::table('settings')->where('key', 'cancellation_window_hours')->delete();
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn('maps_url');
        });
    }
};
