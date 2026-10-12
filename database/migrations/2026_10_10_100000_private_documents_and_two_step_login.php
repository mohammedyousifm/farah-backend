<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * 1. Provider documents (IDs, commercial registers, IBAN letters) move from the public disk to the
 *    private one; they are then only reachable through short-lived signed links.
 * 2. Columns for two-step login (authenticator app) on the panels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable()->after('remember_token');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });

        $public = Storage::disk('public');
        $private = Storage::disk('local');

        foreach (DB::table('provider_documents')->whereNotNull('file_path')->distinct()->pluck('file_path') as $path) {
            if (! $public->exists($path)) {
                continue;
            }
            if (! $private->exists($path)) {
                $private->put($path, $public->get($path));
            }
            // Shared demo images stay where the seeder put them; real uploads leave the public disk.
            if (! str_starts_with($path, 'demo/')) {
                $public->delete($path);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
