<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\User::create([
            'name' => 'Super Admin',
            'email' => config('app.superadmin_email', 'admin@example.com'),
            'password' => \Illuminate\Support\Facades\Hash::make(config('app.superadmin_password', 'password')),
            'is_super_admin' => true,
        ]);
    }
}
