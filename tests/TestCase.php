<?php

namespace Tests;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tymon\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Guard de la API (config/auth.php: driver jwt).
     */
    protected string $guard = 'api';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    /**
     * Siembra las plantillas globales de roles/permisos (guard api, tenant_id
     * null). El seeder ya es idempotente: firstOrCreate + syncPermissions.
     */
    protected function seedRolesAndPermissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Crea un tenant activo y un usuario suyo con los permisos indicados.
     *
     * @param  array<int, string>  $permissions
     * @param  array<string, mixed>  $attributes  atributos extra del usuario
     * @return array{0: \App\Models\User, 1: string} [$user, $token]
     */
    protected function createTenantWithUser(array $permissions = [], ?Tenant $tenant = null, array $attributes = []): array
    {
        $tenant ??= Tenant::factory()->create();

        $user = User::factory()->forTenant($tenant)->create($attributes);

        $this->givePermissions($user, $permissions);

        return [$user, JWTAuth::fromUser($user)];
    }

    /**
     * Autentica las siguientes peticiones como el usuario dado
     * (header Authorization: Bearer <jwt>).
     */
    protected function actingAsTenantUser(User $user, ?string $token = null): static
    {
        return $this->withHeader('Authorization', 'Bearer '.($token ?? JWTAuth::fromUser($user)));
    }

    /**
     * Crea un super admin global (is_super_admin = true, tenant_id = null) y
     * autentica como él. EnsureTenant no fija current_tenant_id para este perfil,
     * por lo que TenantScope no filtra.
     */
    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAsTenantUser($user);

        return $user;
    }

    /**
     * Asigna permisos al usuario creando los que falten con el guard de la API.
     *
     * @param  array<int, string>  $permissions
     */
    protected function givePermissions(User $user, array $permissions): void
    {
        if ($permissions === []) {
            return;
        }

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => $this->guard]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user->givePermissionTo($permissions);
    }
}
