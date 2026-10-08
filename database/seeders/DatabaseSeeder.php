<?php

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ReferenceDataSeeder::class,
        ]);

        $admins = [
            ['مدير النظام', 'admin@farah.sa', 'super_admin'],
            ['فريق العمليات', 'ops@farah.sa', 'operations'],
            ['الإدارة المالية', 'finance@farah.sa', 'finance'],
            ['فريق المحتوى', 'content@farah.sa', 'content'],
            ['خدمة العملاء', 'support@farah.sa', 'support'],
        ];

        foreach ($admins as [$name, $email, $role]) {
            $user = User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
                'type' => UserType::Admin,
                'email_verified_at' => now(),
            ]);
            $user->syncRoles([$role]);
        }

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
