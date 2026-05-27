<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Idempotent: safe to run on every container boot. Credentials come from
     * SUPERADMIN_* env (config/app.php). The global super admin has no tenant.
     */
    public function run(): void
    {
        \App\Models\User::updateOrCreate(
            ['email' => config('app.superadmin_email')],
            [
                'name' => config('app.superadmin_name', 'Super Administrator'),
                'password' => Hash::make(config('app.superadmin_password')),
                'is_super_admin' => true,
                'tenant_id' => null,
            ]
        );
    }
}
