<?php

namespace Tests\Feature\Tenants;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * `POST /tenants` delega en TenantProvisioningService (Spec 0003). Estas pruebas
 * fijan el contrato del endpoint y que el resultado sea el mismo que produce el
 * seeder demo: roles clonados con tenant_id, admin con su rol y créditos.
 */
class TenantProvisioningTest extends TestCase
{
    public function test_el_super_admin_crea_un_tenant_completo(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/v1/tenants', $this->payload());

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'slug', 'nombre'],
                'admin' => ['id', 'name', 'email'],
                'message',
            ])
            ->assertJsonPath('data.slug', 'gobernacion-demo')
            ->assertJsonPath('admin.email', 'admin@gobernacion-demo.test');

        $tenantId = $response->json('data.id');

        $this->assertDatabaseHas('tenant_messaging_credits', [
            'tenant_id' => $tenantId,
            'emails_available' => 1000,
            'whatsapp_available' => 500,
        ]);

        $roles = Role::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['admin', 'coordinator', 'operator', 'viewer'], $roles);
    }

    public function test_el_admin_creado_recibe_el_rol_admin_de_su_tenant_no_el_global(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/v1/tenants', $this->payload());
        $response->assertStatus(201);

        $admin = User::withoutGlobalScope(TenantScope::class)
            ->findOrFail($response->json('admin.id'));

        $rol = $admin->roles->first();

        $this->assertNotNull($rol);
        $this->assertSame('admin', $rol->name);
        $this->assertSame($response->json('data.id'), $rol->tenant_id);
        $this->assertNotNull($rol->tenant_id, 'No puede ser la plantilla global.');
        $this->assertEqualsCanonicalizing(
            Permissions::all(),
            $rol->permissions->pluck('name')->all()
        );
    }

    public function test_un_usuario_de_tenant_no_puede_crear_tenants(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->postJson('/api/v1/tenants', $this->payload())
            ->assertStatus(403);

        $this->assertDatabaseMissing('tenants', ['slug' => 'gobernacion-demo']);
    }

    public function test_sin_token_no_se_puede_crear_un_tenant(): void
    {
        $this->postJson('/api/v1/tenants', $this->payload())->assertStatus(401);
    }

    public function test_valida_el_payload(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/v1/tenants', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug', 'nombre', 'tipo_cargo', 'admin_email']);
    }

    public function test_dos_tenants_pueden_tener_cada_uno_su_rol_admin(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/v1/tenants', $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/tenants', $this->payload([
            'slug' => 'alcaldia-demo',
            'nombre' => 'Alcaldía Demo',
            'identificacion' => '900999888',
            'admin_email' => 'admin@alcaldia-demo.test',
        ]))->assertStatus(201);

        $adminsPorTenant = Role::withoutGlobalScope(TenantScope::class)
            ->where('name', 'admin')
            ->whereNotNull('tenant_id')
            ->count();

        $this->assertSame(2, $adminsPorTenant);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'slug' => 'gobernacion-demo',
            'nombre' => 'Gobernación Demo',
            'tipo_cargo' => 'Gobernacion',
            'identificacion' => '900111222',
            'email_contacto' => 'contacto@gobernacion-demo.test',
            'admin_name' => 'Admin Gobernación',
            'admin_email' => 'admin@gobernacion-demo.test',
            'admin_password' => 'secret1234',
        ], $extra);
    }
}
