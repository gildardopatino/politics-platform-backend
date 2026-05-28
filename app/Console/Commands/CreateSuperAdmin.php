<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateSuperAdmin extends Command
{
    /**
     * Create (or update) the global super admin — the user with
     * is_super_admin = true and no tenant. Run interactively:
     *   php artisan superadmin:create
     * or non-interactively:
     *   php artisan superadmin:create --email=you@x.com --password=secret --name="You"
     */
    protected $signature = 'superadmin:create
        {--email= : Super admin email}
        {--password= : Super admin password (min 8)}
        {--name= : Display name}';

    protected $description = 'Create or update the global super admin user';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email del superadmin');
        $name = $this->option('name') ?: $this->ask('Nombre', 'Super Administrator');
        $password = $this->option('password') ?: $this->secret('Contraseña (mín 8 caracteres)');

        $validator = Validator::make(
            compact('email', 'name', 'password'),
            [
                'email' => 'required|email',
                'name' => 'required|string|max:255',
                'password' => 'required|string|min:8',
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->exists();

        // password is hashed by the model's "hashed" cast — assign plain text.
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'is_super_admin' => true,
                'tenant_id' => null,
            ]
        );

        $this->info(($existing ? 'Super admin actualizado: ' : 'Super admin creado: ') . $user->email);

        return self::SUCCESS;
    }
}
