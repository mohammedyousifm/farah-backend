<?php

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ReferenceDataSeeder::class,
            PlanSeeder::class,
        ]);

        $admins = [
            ['مدير النظام', 'admin@farah.sa', 'super_admin'],
            ['فريق العمليات', 'ops@farah.sa', 'operations'],
            ['الإدارة المالية', 'finance@farah.sa', 'finance'],
            ['فريق المحتوى', 'content@farah.sa', 'content'],
            ['خدمة العملاء', 'support@farah.sa', 'support'],
        ];

        // Local and test set-ups use a known password. Anywhere else every new admin gets a random
        // one, printed once here: sign in with it and change it.
        $known = app()->environment('local', 'testing');

        foreach ($admins as [$name, $email, $role]) {
            $password = $known ? 'password' : Str::password(20);
            $user = User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => $password,
                'type' => UserType::Admin,
                'email_verified_at' => now(),
            ]);
            $user->syncRoles([$role]);
            if (! $known && $user->wasRecentlyCreated) {
                $this->command?->warn("Admin {$email} was created with the password: {$password}");
            }
        }

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
