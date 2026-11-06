<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The provider dashboard now asks a new provider to confirm the e-mail address. Accounts that existed before
 * that rule keep working: they are marked as confirmed once. New sign-ups get the link by e-mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->whereNotNull('email')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Nothing to undo: there is no record of which rows were empty before.
    }
};
