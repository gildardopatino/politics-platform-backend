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
        $email = config('app.superadmin_email');
        $password = config('app.superadmin_password');

        // Opt-in: only auto-provision when BOTH credentials are configured.
        // Otherwise skip (avoids creating a super admin with an empty/unusable
        // password). Use `php artisan superadmin:create` to create one manually.
        if (empty($email) || empty($password)) {
            $this->command?->warn('SuperAdminSeeder skipped: set SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD, or run `php artisan superadmin:create`.');

            return;
        }

        \App\Models\User::updateOrCreate(
            ['email' => $email],
            [
                'name' => config('app.superadmin_name', 'Super Administrator'),
                'password' => Hash::make($password),
                'is_super_admin' => true,
                'tenant_id' => null,
            ]
        );
    }
}
