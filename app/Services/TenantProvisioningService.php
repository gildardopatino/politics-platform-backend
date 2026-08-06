<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMessagingCredit;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Alta completa de un tenant (Spec 0003).
 *
 * Fuente única: la usan `TenantController@store` y `DemoDataSeeder`, para que un
 * tenant creado por la API y uno creado por el seeder queden idénticos (antes el
 * seeder asignaba los roles GLOBALES a usuarios de tenant, saltándose el clonado
 * por tenant que hace el flujo real).
 *
 * Provisionar significa: tenant + créditos de mensajería + el juego de roles
 * clonado del catálogo global + el administrador inicial. Todo en una
 * transacción; idempotente por slug y por email del admin.
 */
class TenantProvisioningService
{
    /**
     * Roles plantilla globales que se clonan a cada tenant.
     *
     * @var array<int, string>
     */
    public const ROLES_PLANTILLA = ['admin', 'coordinator', 'operator', 'viewer'];

    public const CREDITOS_EMAIL_POR_DEFECTO = 1000;

    public const CREDITOS_WHATSAPP_POR_DEFECTO = 500;

    /**
     * Claves de $data que describen al admin o a los créditos, no al tenant.
     *
     * @var array<int, string>
     */
    private const CLAVES_NO_TENANT = [
        'admin_name', 'admin_email', 'admin_password', 'initial_emails', 'initial_whatsapp',
    ];

    /**
     * @param  array<string, mixed>  $data  atributos del tenant + admin_name,
     *                                      admin_email, admin_password y,
     *                                      opcionalmente, initial_emails /
     *                                      initial_whatsapp.
     */
    public function provision(array $data): Tenant
    {
        return DB::transaction(function () use ($data) {
            $tenant = $this->crearTenant($data);

            $this->inicializarCreditos($tenant, $data);
            $this->clonarRoles($tenant);
            $this->crearAdmin($tenant, $data);

            return $tenant;
        });
    }

    /**
     * El administrador inicial del tenant. Útil para responder al cliente de la
     * API después de provisionar.
     */
    public function adminDe(Tenant $tenant): ?User
    {
        return User::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function crearTenant(array $data): Tenant
    {
        $atributos = Arr::except($data, self::CLAVES_NO_TENANT);

        $existente = Tenant::where('slug', $atributos['slug'])->first();

        if ($existente) {
            return $existente;
        }

        return Tenant::create($atributos);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function inicializarCreditos(Tenant $tenant, array $data): void
    {
        TenantMessagingCredit::firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'emails_available' => $data['initial_emails'] ?? self::CREDITOS_EMAIL_POR_DEFECTO,
                'whatsapp_available' => $data['initial_whatsapp'] ?? self::CREDITOS_WHATSAPP_POR_DEFECTO,
            ]
        );
    }

    /**
     * Clona las plantillas globales (tenant_id null) a roles propios del tenant,
     * conservando sus permisos. Si no hay plantillas sembradas, crea al menos un
     * admin con todos los permisos.
     */
    private function clonarRoles(Tenant $tenant): void
    {
        $plantillas = Role::withoutGlobalScope(TenantScope::class)
            ->whereNull('tenant_id')
            ->where('guard_name', 'api')
            ->whereIn('name', self::ROLES_PLANTILLA)
            ->with('permissions')
            ->get();

        if ($plantillas->isEmpty()) {
            $admin = $this->rolDelTenant($tenant, 'admin') ?? $this->crearRol($tenant, 'admin');

            $admin->syncPermissions(Permission::all());

            return;
        }

        foreach ($plantillas as $plantilla) {
            $role = $this->rolDelTenant($tenant, $plantilla->name)
                ?? $this->crearRol($tenant, $plantilla->name);

            $role->syncPermissions($plantilla->permissions);
        }
    }

    /**
     * Crea un rol del tenant sin pasar por `Role::create()` de Spatie.
     *
     * Ese método rechaza cualquier rol cuyo par (name, guard_name) ya exista,
     * porque asume la unicidad que trae el paquete. Aquí los roles se scopean por
     * tenant: la clave única real es (tenant_id, name, guard_name) —ver la
     * migración `fix_roles_unique_for_tenants`— y cada tenant tiene su propio
     * `admin`. Con la comprobación de Spatie, clonar las plantillas globales
     * revienta con RoleAlreadyExists en cuanto existe el `admin` global.
     */
    private function crearRol(Tenant $tenant, string $nombre): Role
    {
        return Role::query()->create([
            'name' => $nombre,
            'guard_name' => 'api',
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function crearAdmin(Tenant $tenant, array $data): User
    {
        $admin = User::withoutGlobalScope(TenantScope::class)
            ->where('email', $data['admin_email'])
            ->first();

        if (! $admin) {
            $admin = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'is_super_admin' => false,
            ]);
        }

        $rolAdmin = $this->rolDelTenant($tenant, 'admin');

        if ($rolAdmin && ! $admin->hasRole($rolAdmin)) {
            $admin->assignRole($rolAdmin);
        }

        return $admin;
    }

    private function rolDelTenant(Tenant $tenant, string $nombre): ?Role
    {
        return Role::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('name', $nombre)
            ->where('guard_name', 'api')
            ->first();
    }
}
