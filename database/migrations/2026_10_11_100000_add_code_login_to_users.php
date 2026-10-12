<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Two-step login for the dashboards: which code channels each person switched on (SMS, e-mail, or both). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('has_email_authentication')->default(false)->after('remember_token');
            $table->boolean('has_sms_authentication')->default(false)->after('has_email_authentication');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['has_email_authentication', 'has_sms_authentication']);
        });
    }
};
