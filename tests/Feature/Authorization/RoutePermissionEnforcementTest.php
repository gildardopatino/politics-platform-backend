<?php

namespace Tests\Feature\Authorization;

use App\Models\Meeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voter;
use App\Scopes\TenantScope;
use App\Services\TenantProvisioningService;
use Tests\TestCase;

/**
 * Enforcement por ruta (Spec 0005).
 *
 * Cobertura representativa, no exhaustiva (la auditoría fina es la Spec 0006):
 * meetings con CRUD por verbo, voters con permiso único, geografía/roles/
 * plantillas con `role:admin`, y una ruta deliberadamente abierta.
 */
class RoutePermissionEnforcementTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Sin jerarquía configurada, StoreMeetingRequest rechaza la creación.
        $this->tenant = Tenant::factory()->create([
            'hierarchy_mode' => 'disabled',
            'require_hierarchy_config' => false,
        ]);
    }

    // ------------------------------------------------------------------
    // Meetings — módulo con catálogo CRUD completo: un permiso por verbo.
    // ------------------------------------------------------------------

    public function test_listar_reuniones_exige_view_meetings(): void
    {
        $this->comoUsuarioCon([])->getJson('/api/v1/meetings')->assertStatus(403);

        $this->comoUsuarioCon(['view_meetings'])->getJson('/api/v1/meetings')->assertStatus(200);
    }

    public function test_ver_una_reunion_exige_view_meetings(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create();

        $this->comoUsuarioCon([])->getJson("/api/v1/meetings/{$meeting->id}")->assertStatus(403);

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson("/api/v1/meetings/{$meeting->id}")
            ->assertStatus(200);
    }

    public function test_crear_una_reunion_exige_create_meetings(): void
    {
        // Tener view_meetings no alcanza para crear.
        $this->comoUsuarioCon(['view_meetings'])
            ->postJson('/api/v1/meetings', $this->payloadReunion())
            ->assertStatus(403);

        $usuario = $this->usuarioCon(['view_meetings', 'create_meetings']);

        $this->actingAsTenantUser($usuario)
            ->postJson('/api/v1/meetings', $this->payloadReunion($usuario))
            ->assertStatus(201);
    }

    public function test_editar_una_reunion_exige_edit_meetings(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create();

        $this->comoUsuarioCon(['view_meetings'])
            ->putJson("/api/v1/meetings/{$meeting->id}", ['title' => 'Nueva'])
            ->assertStatus(403);

        $this->comoUsuarioCon(['edit_meetings'])
            ->putJson("/api/v1/meetings/{$meeting->id}", ['title' => 'Nueva'])
            ->assertStatus(200);
    }

    public function test_borrar_una_reunion_exige_delete_meetings(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create();

        $this->comoUsuarioCon(['edit_meetings'])
            ->deleteJson("/api/v1/meetings/{$meeting->id}")
            ->assertStatus(403);

        $this->comoUsuarioCon(['delete_meetings'])
            ->deleteJson("/api/v1/meetings/{$meeting->id}")
            ->assertStatus(200);
    }

    public function test_las_acciones_de_reunion_exigen_edit_meetings(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create();

        $this->comoUsuarioCon(['view_meetings'])
            ->postJson("/api/v1/meetings/{$meeting->id}/complete")
            ->assertStatus(403);

        $this->comoUsuarioCon(['edit_meetings'])
            ->postJson("/api/v1/meetings/{$meeting->id}/complete")
            ->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // Voters — módulo de permiso único: `view_voters` gatea todas sus acciones.
    // ------------------------------------------------------------------

    public function test_listar_votantes_exige_view_voters(): void
    {
        $this->comoUsuarioCon([])->getJson('/api/v1/voters')->assertStatus(403);

        $this->comoUsuarioCon(['view_voters'])->getJson('/api/v1/voters')->assertStatus(200);
    }

    public function test_borrar_un_votante_usa_el_mismo_permiso_unico(): void
    {
        $voter = Voter::factory()->forTenant($this->tenant)->create();

        $this->comoUsuarioCon([])
            ->deleteJson("/api/v1/voters/{$voter->id}")
            ->assertStatus(403);

        $this->comoUsuarioCon(['view_voters'])
            ->deleteJson("/api/v1/voters/{$voter->id}")
            ->assertStatus(200);
    }

    public function test_las_estadisticas_de_votantes_exigen_view_voters(): void
    {
        $this->comoUsuarioCon([])->getJson('/api/v1/voters-stats')->assertStatus(403);

        $this->comoUsuarioCon(['view_voters'])->getJson('/api/v1/voters-stats')->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // role:admin — el frontend marca estas pantallas con requiredRole="admin".
    // ------------------------------------------------------------------

    public function test_el_crud_de_geografia_exige_rol_admin(): void
    {
        $this->comoUsuarioCon(['view_meetings'])
            ->getJson('/api/v1/municipalities')
            ->assertStatus(403);

        $this->actingAsTenantUser($this->adminDelTenant())
            ->getJson('/api/v1/municipalities')
            ->assertStatus(200);
    }

    public function test_los_roles_exigen_rol_admin(): void
    {
        $this->comoUsuarioCon(['view_users'])
            ->getJson('/api/v1/roles')
            ->assertStatus(403);

        $this->actingAsTenantUser($this->adminDelTenant())
            ->getJson('/api/v1/roles')
            ->assertStatus(200);
    }

    public function test_las_plantillas_de_reunion_exigen_rol_admin(): void
    {
        $this->comoUsuarioCon(['view_meetings'])
            ->getJson('/api/v1/meeting-templates')
            ->assertStatus(403);

        $this->actingAsTenantUser($this->adminDelTenant())
            ->getJson('/api/v1/meeting-templates')
            ->assertStatus(200);
    }

    public function test_un_permiso_suelto_no_sustituye_al_rol_admin(): void
    {
        // El bypass es solo del super admin: tener permisos del módulo no
        // convierte a nadie en admin del tenant.
        $this->comoUsuarioCon(['view_users', 'create_users', 'edit_users', 'delete_users'])
            ->getJson('/api/v1/roles')
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Rutas sin permiso declarado en el frontend: siguen abiertas al tenant.
    // ------------------------------------------------------------------

    public function test_el_calendario_no_exige_permiso(): void
    {
        $this->comoUsuarioCon([])->getJson('/api/v1/calendar')->assertStatus(200);
    }

    public function test_las_lecturas_de_geografia_no_exigen_permiso(): void
    {
        // Las usan los selectores de casi todos los formularios; solo el CRUD
        // de geografía es de admin.
        $this->comoUsuarioCon([])->getJson('/api/v1/departments')->assertStatus(200);
    }

    public function test_mi_equipo_no_exige_permiso(): void
    {
        $this->comoUsuarioCon([])->getJson('/api/v1/organization/my-team')->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // Super admin.
    // ------------------------------------------------------------------

    public function test_el_super_admin_pasa_permisos_y_roles(): void
    {
        $this->actingAsSuperAdmin();

        $this->getJson('/api/v1/meetings')->assertStatus(200);
        $this->getJson('/api/v1/voters')->assertStatus(200);
        $this->getJson('/api/v1/roles')->assertStatus(200);
        $this->getJson('/api/v1/municipalities')->assertStatus(200);
    }

    public function test_sin_token_las_rutas_protegidas_responden_401(): void
    {
        $this->getJson('/api/v1/meetings')->assertStatus(401);
        $this->getJson('/api/v1/roles')->assertStatus(401);
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<int, string>  $permisos
     */
    private function comoUsuarioCon(array $permisos): static
    {
        return $this->actingAsTenantUser($this->usuarioCon($permisos));
    }

    /**
     * @param  array<int, string>  $permisos
     */
    private function usuarioCon(array $permisos): User
    {
        [$user] = $this->createTenantWithUser($permisos, $this->tenant);

        return $user;
    }

    /**
     * Un usuario con el rol `admin` CLONADO de este mismo tenant (el que produce
     * TenantProvisioningService), no la plantilla global.
     *
     * El servicio es idempotente por slug, así que pasarle los datos del tenant
     * ya creado clona sus roles y le añade el admin sin duplicar nada. Tiene que
     * ser el MISMO tenant: dos peticiones a tenants distintos dentro de una
     * prueba comparten el contenedor, y el `current_tenant_id` de la primera
     * haría que la segunda no encuentre al usuario (401).
     */
    private function adminDelTenant(): User
    {
        app(TenantProvisioningService::class)->provision([
            'slug' => $this->tenant->slug,
            'nombre' => $this->tenant->nombre,
            'tipo_cargo' => $this->tenant->tipo_cargo,
            'identificacion' => $this->tenant->identificacion,
            'email_contacto' => $this->tenant->email_contacto,
            'admin_name' => 'Admin del tenant',
            'admin_email' => 'admin@tenant-de-prueba.test',
            'admin_password' => 'secret1234',
        ]);

        return User::withoutGlobalScope(TenantScope::class)
            ->where('email', 'admin@tenant-de-prueba.test')
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadReunion(?User $planner = null): array
    {
        return [
            'title' => 'Reunión de prueba',
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'planner_user_id' => $planner?->id ?? $this->usuarioCon([])->id,
            'lugar_nombre' => 'Salón comunal',
        ];
    }
}
