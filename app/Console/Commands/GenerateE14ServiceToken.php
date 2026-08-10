<?php

namespace App\Console\Commands;

use App\Models\E14ServiceToken;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Genera (o rota) el token con el que el lector de actas publica (Spec 0061).
 *
 * Crea de paso el usuario de servicio del tenant, con `view_e14`/`manage_e14` y
 * nada más: si el token se filtra, lo que se pierde es la capacidad de publicar
 * actas de una campaña, no una sesión de administrador.
 *
 * En la base solo queda el SHA-256, así que el valor en claro se imprime **una
 * sola vez**.
 */
class GenerateE14ServiceToken extends Command
{
    protected $signature = 'e14:token
                            {tenant : id o slug del tenant}
                            {--rotate : revoca el token vigente y emite uno nuevo}';

    protected $description = 'Genera (o rota) el token de servicio del lector de actas E-14 de un tenant';

    public function handle(): int
    {
        $tenant = Tenant::withoutGlobalScope(TenantScope::class)
            ->where('id', $this->argument('tenant'))
            ->orWhere('slug', $this->argument('tenant'))
            ->first();

        if (! $tenant) {
            $this->error("No existe el tenant «{$this->argument('tenant')}».");

            return self::FAILURE;
        }

        $vigentes = E14ServiceToken::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->whereNull('revoked_at');

        if ((clone $vigentes)->exists() && ! $this->option('rotate')) {
            $this->error("El tenant «{$tenant->slug}» ya tiene un token de E-14 vigente. Usa --rotate para reemplazarlo.");
            $this->line('Rotar invalida el anterior de inmediato: el lector dejará de publicar hasta que se actualice su .env.');

            return self::FAILURE;
        }

        $usuario = $this->usuarioDeServicio($tenant);

        // Se revoca en vez de borrar: queda el rastro de cuándo se usó por
        // última vez el token anterior, que es lo primero que se mira si algo
        // aparece publicado y nadie sabe quién lo publicó.
        (clone $vigentes)->update(['revoked_at' => now()]);

        $valor = E14ServiceToken::PREFIJO.bin2hex(random_bytes(24));

        E14ServiceToken::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'user_id' => $usuario->id,
            'nombre' => 'lector e14',
            'token_hash' => E14ServiceToken::hashDe($valor),
        ]);

        $this->newLine();
        $this->info("Token de E-14 para «{$tenant->nombre}» (slug: {$tenant->slug}, id: {$tenant->id}):");
        $this->newLine();
        $this->line("  {$valor}");
        $this->newLine();
        $this->comment('Se muestra UNA sola vez: en la base solo queda su SHA-256.');
        $this->comment('Ponlo en el .env del lector (plafform-politics-e14):');
        $this->line('  BACKEND_API_TOKEN=<el valor de arriba>');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * El usuario al que cuelga el token. Sin él, la publicación no tendría a
     * quién atribuirse en la auditoría ni de quién sacar el tenant.
     */
    private function usuarioDeServicio(Tenant $tenant): User
    {
        $correo = "e14-reader+{$tenant->slug}@servicio.local";

        $usuario = User::withoutGlobalScope(TenantScope::class)
            ->where('email', $correo)
            ->first();

        if (! $usuario) {
            $usuario = new User;
            $usuario->forceFill([
                'tenant_id' => $tenant->id,
                'name' => "Lector E-14 ({$tenant->slug})",
                'email' => $correo,
                // Contraseña imposible de usar: este usuario no inicia sesión,
                // solo existe para que el token tenga dueño.
                'password' => Hash::make(Str::random(64)),
                'is_super_admin' => false,
            ])->save();
        }

        foreach ([Permissions::VIEW_E14, Permissions::MANAGE_E14] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => Permissions::GUARD]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $usuario->syncPermissions([Permissions::VIEW_E14, Permissions::MANAGE_E14]);

        return $usuario;
    }
}
