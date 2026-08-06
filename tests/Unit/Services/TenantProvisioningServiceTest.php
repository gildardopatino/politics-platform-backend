<?php

namespace Tests\Unit\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\TenantProvisioningService;
use App\Support\Permissions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Fuente única de aprovisionamiento (Spec 0003): tanto `POST /tenants` como el
 * seeder demo pasan por aquí, así que un tenant creado por API y otro creado por
 * el seeder quedan idénticos.
 */
class TenantProvisioningServiceTest extends TestCase
{
    private TenantProvisioningService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TenantProvisioningService::class);
    }

    public function test_crea_el_tenant_con_los_datos_dados(): void
    {
        $tenant = $this->service->provision($this->datos());

        $this->assertInstanceOf(Tenant::class, $tenant);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'slug' => 'alcaldia-demo',
            'nombre' => 'Alcaldía Demo',
            'tipo_cargo' => 'Alcaldia',
        ]);
    }

    public function test_clona_los_cuatro_roles_plantilla_con_el_tenant_id(): void
    {
        $tenant = $this->service->provision($this->datos());

        $roles = Role::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['admin', 'coordinator', 'operator', 'viewer'], $roles);
    }

    public function test_los_roles_clonados_heredan_los_permisos_de_su_plantilla(): void
    {
        $tenant = $this->service->provision($this->datos());

        $admin = $this->rolDelTenant($tenant, 'admin');
        $viewer = $this->rolDelTenant($tenant, 'viewer');

        $this->assertEqualsCanonicalizing(
            Permissions::all(),
            $admin->permissions->pluck('name')->all()
        );
        $this->assertEqualsCanonicalizing(
            Permissions::byRole()['viewer'],
            $viewer->permissions->pluck('name')->all()
        );
    }

    public function test_crea_el_admin_inicial_con_el_rol_admin_de_su_tenant(): void
    {
        $tenant = $this->service->provision($this->datos());

        $admin = User::withoutGlobalScope(TenantScope::class)
            ->where('email', 'admin@alcaldia-demo.test')
            ->first();

        $this->assertNotNull($admin);
        $this->assertSame($tenant->id, $admin->tenant_id);
        $this->assertFalse($admin->is_super_admin);
        $this->assertTrue(Hash::check('secret1234', $admin->password));

        $rolAsignado = $admin->roles->first();

        $this->assertNotNull($rolAsignado, 'El admin debe quedar con un rol.');
        $this->assertSame('admin', $rolAsignado->name);
        $this->assertSame(
            $tenant->id,
            $rolAsignado->tenant_id,
            'Debe ser el rol clonado del tenant, no la plantilla global.'
        );
    }

    public function test_inicializa_los_creditos_de_mensajeria(): void
    {
        $tenant = $this->service->provision($this->datos([
            'initial_emails' => 250,
            'initial_whatsapp' => 75,
        ]));

        $this->assertDatabaseHas('tenant_messaging_credits', [
            'tenant_id' => $tenant->id,
            'emails_available' => 250,
            'whatsapp_available' => 75,
        ]);
    }

    public function test_usa_valores_por_defecto_de_creditos_si_no_se_indican(): void
    {
        $tenant = $this->service->provision($this->datos());

        $this->assertDatabaseHas('tenant_messaging_credits', [
            'tenant_id' => $tenant->id,
            'emails_available' => 1000,
            'whatsapp_available' => 500,
        ]);
    }

    public function test_es_idempotente_por_slug(): void
    {
        $primero = $this->service->provision($this->datos());
        $segundo = $this->service->provision($this->datos());

        $this->assertSame($primero->id, $segundo->id, 'No debe duplicar el tenant.');
        $this->assertSame(1, Tenant::where('slug', 'alcaldia-demo')->count());
        $this->assertSame(
            4,
            Role::withoutGlobalScope(TenantScope::class)->where('tenant_id', $primero->id)->count(),
            'No debe duplicar los roles clonados.'
        );
        $this->assertSame(
            1,
            User::withoutGlobalScope(TenantScope::class)->where('email', 'admin@alcaldia-demo.test')->count(),
            'No debe duplicar el admin.'
        );
    }

    public function test_si_no_hay_plantillas_globales_crea_un_admin_con_todos_los_permisos(): void
    {
        Role::withoutGlobalScope(TenantScope::class)->whereNull('tenant_id')->delete();

        $tenant = $this->service->provision($this->datos());

        $roles = Role::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->get();

        $this->assertCount(1, $roles);
        $this->assertSame('admin', $roles->first()->name);
        $this->assertEqualsCanonicalizing(
            Permissions::all(),
            $roles->first()->permissions->pluck('name')->all()
        );
    }

    public function test_no_deja_rastro_si_falla_a_mitad(): void
    {
        $tenantsAntes = Tenant::count();

        // tipo_cargo viola el enum de la columna: la creación revienta.
        try {
            $this->service->provision($this->datos(['tipo_cargo' => str_repeat('x', 300)]));
            $this->fail('Se esperaba una excepción.');
        } catch (\Throwable) {
            // esperado
        }

        $this->assertSame($tenantsAntes, Tenant::count(), 'La transacción debe revertirse.');
        $this->assertDatabaseMissing('users', ['email' => 'admin@alcaldia-demo.test']);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'slug' => 'alcaldia-demo',
            'nombre' => 'Alcaldía Demo',
            'tipo_cargo' => 'Alcaldia',
            'identificacion' => '900123456',
            'email_contacto' => 'contacto@alcaldia-demo.test',
            'admin_name' => 'Admin Demo',
            'admin_email' => 'admin@alcaldia-demo.test',
            'admin_password' => 'secret1234',
        ], $extra);
    }

    private function rolDelTenant(Tenant $tenant, string $nombre): Role
    {
        return Role::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('name', $nombre)
            ->firstOrFail();
    }
}
