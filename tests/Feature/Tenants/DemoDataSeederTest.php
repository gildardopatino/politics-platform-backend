<?php

namespace Tests\Feature\Tenants;

use App\Models\Campaign;
use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\ResourceItem;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voter;
use App\Scopes\TenantScope;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\PrioritySeeder;
use Database\Seeders\TipoVotanteSeeder;
use Tests\TestCase;

/**
 * El seeder demo es la vía por la que se puebla la base con `migrate:fresh
 * --seed` (Spec 0003). Antes reventaba —MeetingAttendee sin `tenant_id`— y
 * asignaba los roles GLOBALES a usuarios de tenant.
 */
class DemoDataSeederTest extends TestCase
{
    /**
     * Tenants demo esperados, con su dominio de correo.
     *
     * @var array<string, string>
     */
    private const TENANTS = [
        'alcaldia-medellin' => 'medellin.demo',
        'gobernacion-antioquia' => 'antioquia.demo',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // El setUp del TestCase ya sembró roles/permisos globales.
        $this->seed(GeographySeeder::class);
        $this->seed(PrioritySeeder::class);
        $this->seed(TipoVotanteSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    public function test_crea_los_tenants_demo(): void
    {
        foreach (array_keys(self::TENANTS) as $slug) {
            $this->assertDatabaseHas('tenants', ['slug' => $slug]);
        }

        $this->assertSame(count(self::TENANTS), Tenant::count());
    }

    public function test_cada_tenant_tiene_sus_cuatro_roles_clonados(): void
    {
        foreach (array_keys(self::TENANTS) as $slug) {
            $tenant = Tenant::where('slug', $slug)->firstOrFail();

            $roles = Role::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)
                ->pluck('name')
                ->sort()
                ->values()
                ->all();

            $this->assertSame(['admin', 'coordinator', 'operator', 'viewer'], $roles, "Tenant {$slug}");
        }
    }

    public function test_cada_usuario_demo_tiene_un_rol_con_el_tenant_id_de_su_tenant(): void
    {
        $usuarios = User::withoutGlobalScope(TenantScope::class)
            ->whereNotNull('tenant_id')
            ->with('roles')
            ->get();

        $this->assertCount(8, $usuarios, 'Cuatro usuarios por tenant (admin + 3 roles).');

        foreach ($usuarios as $usuario) {
            $rol = $usuario->roles->first();

            $this->assertNotNull($rol, "El usuario {$usuario->email} debe tener rol.");
            $this->assertSame(
                $usuario->tenant_id,
                $rol->tenant_id,
                "El rol de {$usuario->email} debe ser el clonado de su tenant, no la plantilla global."
            );
        }
    }

    public function test_los_usuarios_demo_cubren_los_cuatro_roles_en_cada_tenant(): void
    {
        foreach (self::TENANTS as $slug => $dominio) {
            $tenant = Tenant::where('slug', $slug)->firstOrFail();

            $roles = User::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)
                ->with('roles')
                ->get()
                ->map(fn (User $u) => $u->roles->first()?->name)
                ->sort()
                ->values()
                ->all();

            $this->assertSame(['admin', 'coordinator', 'operator', 'viewer'], $roles, "Tenant {$slug}");

            foreach (['admin', 'coordinador', 'operador', 'visor'] as $prefijo) {
                $this->assertDatabaseHas('users', ['email' => "{$prefijo}@{$dominio}"]);
            }
        }
    }

    public function test_puebla_cada_modulo_para_cada_tenant(): void
    {
        foreach (array_keys(self::TENANTS) as $slug) {
            $tenant = Tenant::where('slug', $slug)->firstOrFail();

            $this->assertSame(4, $this->contar(Voter::class, $tenant), "votantes de {$slug}");
            $this->assertSame(2, $this->contar(Meeting::class, $tenant), "reuniones de {$slug}");
            $this->assertSame(3, $this->contar(Commitment::class, $tenant), "compromisos de {$slug}");
            $this->assertSame(1, $this->contar(Campaign::class, $tenant), "campañas de {$slug}");
            $this->assertSame(4, $this->contar(ResourceItem::class, $tenant), "recursos de {$slug}");

            $this->assertDatabaseHas('landing_banners', ['tenant_id' => $tenant->id]);
            $this->assertDatabaseHas('landing_propuestas', ['tenant_id' => $tenant->id]);
            $this->assertDatabaseHas('landing_eventos', ['tenant_id' => $tenant->id]);
            $this->assertDatabaseHas('tenant_messaging_credits', ['tenant_id' => $tenant->id]);
        }
    }

    public function test_los_asistentes_quedan_con_tenant_id(): void
    {
        // Era el fallo exacto que rompía migrate:fresh --seed: MeetingAttendee
        // tiene tenant_id NOT NULL y en un seeder nadie lo autorrellena.
        $sinTenant = MeetingAttendee::withoutGlobalScope(TenantScope::class)
            ->whereNull('tenant_id')
            ->count();

        $this->assertSame(0, $sinTenant);
        $this->assertSame(
            5,
            MeetingAttendee::withoutGlobalScope(TenantScope::class)->count(),
            '3 asistentes en Medellín + 2 en Antioquia.'
        );
    }

    public function test_hay_un_compromiso_vencido_por_tenant(): void
    {
        foreach (array_keys(self::TENANTS) as $slug) {
            $tenant = Tenant::where('slug', $slug)->firstOrFail();

            $vencidos = Commitment::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)
                ->where('due_date', '<', DemoDataSeeder::FECHA_BASE)
                ->whereIn('status', ['pending', 'in_progress'])
                ->count();

            $this->assertSame(1, $vencidos, "compromisos vencidos de {$slug}");
        }
    }

    public function test_el_admin_demo_solo_ve_los_datos_de_su_tenant(): void
    {
        $medellin = Tenant::where('slug', 'alcaldia-medellin')->firstOrFail();

        $admin = User::withoutGlobalScope(TenantScope::class)
            ->where('email', 'admin@medellin.demo')
            ->firstOrFail();

        $this->actingAsTenantUser($admin);

        $reuniones = $this->getJson('/api/v1/meetings');
        $reuniones->assertStatus(200)->assertJsonPath('meta.total', 2);

        $votantes = $this->getJson('/api/v1/voters');
        $votantes->assertStatus(200);

        $idsAjenos = Voter::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', '!=', $medellin->id)
            ->pluck('id');

        $idsVistos = collect($votantes->json('data'))->pluck('id');

        $this->assertTrue(
            $idsVistos->intersect($idsAjenos)->isEmpty(),
            'El admin de Medellín no puede ver votantes de otro tenant.'
        );
    }

    public function test_el_admin_demo_puede_iniciar_sesion_con_la_clave_documentada(): void
    {
        $this->postJson('/api/v1/login', [
            'email' => 'admin@medellin.demo',
            'password' => DemoDataSeeder::PASSWORD,
        ])->assertStatus(200)->assertJsonStructure(['access_token']);
    }

    public function test_el_seeder_es_idempotente(): void
    {
        $tenantsAntes = Tenant::count();
        $usuariosAntes = User::withoutGlobalScope(TenantScope::class)->count();
        $reunionesAntes = Meeting::withoutGlobalScope(TenantScope::class)->count();

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($tenantsAntes, Tenant::count());
        $this->assertSame($usuariosAntes, User::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame($reunionesAntes, Meeting::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_no_deja_el_tenant_fijado_en_el_contenedor(): void
    {
        // El seeder fija `current_tenant_id` por bloque de tenant; si se quedara
        // con el último, todo lo que consultara después vería solo ese tenant.
        // (Ojo: `instance(x, null)` deja `bound()` en false, que es justo el
        // estado "sin filtro" que espera TenantScope.)
        $this->assertFalse(
            app()->bound('current_tenant_id'),
            'El seeder debe soltar el contexto para no filtrar consultas posteriores.'
        );

        $this->assertSame(
            4,
            Meeting::count(),
            'Sin contexto de tenant, una consulta normal debe ver las reuniones de ambos tenants.'
        );
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelo
     */
    private function contar(string $modelo, Tenant $tenant): int
    {
        return $modelo::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->count();
    }
}
