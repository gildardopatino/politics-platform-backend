<?php

namespace Tests\Feature\Roles;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\TenantProvisioningService;
use Tests\TestCase;

/**
 * Los roles se scopean por tenant: la clave única real es
 * `(tenant_id, name, guard_name)` (ver la migración `fix_roles_unique_for_tenants`),
 * así que cada tenant puede tener su propio `admin`, `editor`, etc.
 *
 * `Role::create()` de Spatie no lo sabe: comprueba `(name, guard_name)` y lanza
 * `RoleAlreadyExists`. Cuando no hay `current_tenant_id` enlazado —el grupo
 * `superadmin`— `TenantScope` no filtra y esa comprobación ve los roles de todos
 * los tenants (Spec 0019).
 */
class RoleTenantScopeTest extends TestCase
{
    public function test_un_tenant_puede_crear_un_rol_que_otro_tenant_ya_tiene(): void
    {
        $tenantB = Tenant::factory()->create();
        $this->rolDeTenant($tenantB, 'editor');

        [$tenantA, $adminA] = $this->tenantConAdmin('alcaldia-a', 'admin@alcaldia-a.test');

        $response = $this->actingAsTenantUser($adminA)->postJson('/api/v1/roles', [
            'name' => 'editor',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'editor');

        $creado = Role::withoutGlobalScope(TenantScope::class)
            ->findOrFail($response->json('data.id'));

        $this->assertSame($tenantA->id, $creado->tenant_id, 'El rol debe quedar en el tenant que lo creó.');
        $this->assertSame(
            2,
            Role::withoutGlobalScope(TenantScope::class)->where('name', 'editor')->count(),
            'Debe haber un `editor` por tenant.'
        );
    }

    public function test_un_tenant_no_puede_duplicar_un_rol_suyo(): void
    {
        [, $adminA] = $this->tenantConAdmin('alcaldia-a', 'admin@alcaldia-a.test');

        $this->actingAsTenantUser($adminA)
            ->postJson('/api/v1/roles', ['name' => 'editor'])
            ->assertStatus(201);

        // Rechazo por validación, no excepción sin manejar.
        $this->actingAsTenantUser($adminA)
            ->postJson('/api/v1/roles', ['name' => 'editor'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Ya existe un rol con este nombre en tu organización');
    }

    public function test_un_tenant_puede_crear_un_rol_que_choca_con_una_plantilla_global(): void
    {
        // `admin`, `coordinator`, `operator` y `viewer` existen como plantillas
        // globales (tenant_id null). Un tenant debe poder tener los suyos.
        [$tenantA, $adminA] = $this->tenantConAdmin('alcaldia-a', 'admin@alcaldia-a.test');

        Role::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantA->id)
            ->where('name', 'viewer')
            ->delete();

        $this->actingAsTenantUser($adminA)
            ->postJson('/api/v1/roles', ['name' => 'viewer'])
            ->assertStatus(201);
    }

    public function test_el_rol_creado_admite_permisos(): void
    {
        [, $adminA] = $this->tenantConAdmin('alcaldia-a', 'admin@alcaldia-a.test');

        $permisos = \Spatie\Permission\Models\Permission::where('guard_name', 'api')
            ->whereIn('name', ['view_meetings', 'view_commitments'])
            ->pluck('id')
            ->all();

        $response = $this->actingAsTenantUser($adminA)->postJson('/api/v1/roles', [
            'name' => 'editor',
            'permissions' => $permisos,
        ]);

        $response->assertStatus(201);

        $creado = Role::withoutGlobalScope(TenantScope::class)
            ->findOrFail($response->json('data.id'));

        $this->assertEqualsCanonicalizing(
            ['view_meetings', 'view_commitments'],
            $creado->permissions->pluck('name')->all()
        );
    }

    /**
     * Alta de admin de tenant desde el grupo `superadmin`, donde NO hay
     * `current_tenant_id` enlazado: ahí `Role::create()` veía el `admin` global.
     */
    public function test_el_super_admin_crea_un_admin_para_un_tenant_sin_roles(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(
            0,
            Role::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count(),
            'El tenant arranca sin roles propios: el controller debe crear el admin.'
        );

        $this->actingAsSuperAdmin();

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/admins", [
            'name' => 'Admin heredado',
            'email' => 'heredado@tenant.test',
            'password' => 'secret1234',
        ]);

        $response->assertStatus(201);

        $usuario = User::withoutGlobalScope(TenantScope::class)
            ->where('email', 'heredado@tenant.test')
            ->firstOrFail();

        $rol = $usuario->roles->first();

        $this->assertNotNull($rol, 'El nuevo admin debe quedar con un rol.');
        $this->assertSame('admin', $rol->name);
        $this->assertSame($tenant->id, $rol->tenant_id, 'Debe ser el admin del tenant, no la plantilla global.');
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantConAdmin(string $slug, string $email): array
    {
        $tenant = app(TenantProvisioningService::class)->provision([
            'slug' => $slug,
            'nombre' => 'Tenant '.$slug,
            'tipo_cargo' => 'Alcaldia',
            'identificacion' => (string) crc32($slug),
            'email_contacto' => "contacto@{$slug}.test",
            'admin_name' => 'Admin',
            'admin_email' => $email,
            'admin_password' => 'secret1234',
        ]);

        $admin = User::withoutGlobalScope(TenantScope::class)->where('email', $email)->firstOrFail();

        return [$tenant, $admin];
    }

    private function rolDeTenant(Tenant $tenant, string $nombre): Role
    {
        return Role::query()->create([
            'name' => $nombre,
            'guard_name' => 'api',
            'tenant_id' => $tenant->id,
        ]);
    }
}
