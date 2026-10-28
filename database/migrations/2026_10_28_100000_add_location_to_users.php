<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The customer's own GPS position (set from the app's profile): the home feed and search list the nearest places first. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('city_id');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            $table->dateTime('location_updated_at')->nullable()->after('lng');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng', 'location_updated_at']);
        });
    }
};
