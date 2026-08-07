<?php

namespace Tests\Feature\Priorities;

use App\Models\Commitment;
use App\Models\Priority;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\TenantProvisioningService;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN del catálogo de prioridades (Spec 0012, Fase 3).
 *
 * `priorities` alimenta el formulario de compromisos. La lectura está abierta a
 * cualquier usuario del tenant; la escritura pide `role:admin`.
 *
 * **La tabla no tiene `tenant_id` ni usa `HasTenant`**: es un catálogo global,
 * igual que `voter-types` (hallazgo de la 0011). Eso tiene consecuencias que se
 * fijan aquí como `test_hallazgo_*`.
 */
class PriorityCharacterizationTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    // ==================================================================
    // Lectura
    // ==================================================================

    public function test_el_listado_va_ordenado_por_order_y_sin_paginar(): void
    {
        $this->crearPrioridad('Urgente', 4);
        $this->crearPrioridad('Baja', 1);
        $this->crearPrioridad('Alta', 3);

        $respuesta = $this->comoUsuarioSinPermisos()->getJson('/api/v1/priorities');

        $respuesta->assertStatus(200)->assertJsonStructure([
            'data' => [['id', 'name', 'description', 'color', 'order']],
        ]);

        $this->assertSame(['Baja', 'Alta', 'Urgente'], collect($respuesta->json('data'))->pluck('name')->all());

        // Sin envoltorio `meta`: el catálogo va entero, no paginado.
        $this->assertNull($respuesta->json('meta'));
    }

    public function test_leer_el_catalogo_no_exige_ningun_permiso(): void
    {
        // El `apiResource` deja index y show sin `permission:` ni `role:`: basta
        // con estar autenticado en un tenant vigente.
        $prioridad = $this->crearPrioridad('Media', 2);

        $this->comoUsuarioSinPermisos()->getJson('/api/v1/priorities')->assertStatus(200);

        $this->comoUsuarioSinPermisos()->getJson("/api/v1/priorities/{$prioridad->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $prioridad->id)
            ->assertJsonPath('data.color', '#ffc107');
    }

    public function test_sin_sesion_el_catalogo_responde_401(): void
    {
        $prioridad = $this->crearPrioridad('Media', 2);

        $this->getJson('/api/v1/priorities')->assertStatus(401);
        $this->getJson("/api/v1/priorities/{$prioridad->id}")->assertStatus(401);
        $this->postJson('/api/v1/priorities', [])->assertStatus(401);
    }

    // ==================================================================
    // Escritura — role:admin
    // ==================================================================

    public function test_crear_editar_y_borrar_exigen_rol_admin(): void
    {
        $prioridad = $this->crearPrioridad('Media', 2);
        $sesion = fn () => $this->comoUsuarioSinPermisos();

        $sesion()->postJson('/api/v1/priorities', $this->payload())->assertStatus(403);
        $sesion()->putJson("/api/v1/priorities/{$prioridad->id}", ['order' => 9])->assertStatus(403);
        $sesion()->deleteJson("/api/v1/priorities/{$prioridad->id}")->assertStatus(403);
    }

    public function test_un_admin_crea_una_prioridad(): void
    {
        $this->comoAdmin()->postJson('/api/v1/priorities', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('message', 'Priority created successfully')
            ->assertJsonPath('data.name', 'Crítica')
            ->assertJsonPath('data.color', '#ff0000')
            ->assertJsonPath('data.order', 5);

        $this->assertDatabaseHas('priorities', ['name' => 'Crítica']);
    }

    public function test_la_creacion_valida_nombre_color_y_orden(): void
    {
        $admin = $this->comoAdmin();

        $admin->postJson('/api/v1/priorities', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'color', 'order']);

        // `color` es exactamente 7 caracteres con formato #RRGGBB.
        $admin->postJson('/api/v1/priorities', $this->payload(['color' => 'rojo']))
            ->assertStatus(422)->assertJsonValidationErrors(['color']);

        $admin->postJson('/api/v1/priorities', $this->payload(['color' => '#GGGGGG']))
            ->assertStatus(422)->assertJsonValidationErrors(['color']);

        $admin->postJson('/api/v1/priorities', $this->payload(['order' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors(['order']);
    }

    public function test_el_nombre_no_se_puede_repetir(): void
    {
        $this->crearPrioridad('Alta', 3);

        $this->comoAdmin()->postJson('/api/v1/priorities', $this->payload(['name' => 'Alta']))
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_actualizar_conserva_su_propio_nombre(): void
    {
        $prioridad = $this->crearPrioridad('Alta', 3);

        // La regla `unique` se construye ignorando el id propio, así que
        // reenviar el mismo nombre no choca consigo mismo.
        $this->comoAdmin()->putJson("/api/v1/priorities/{$prioridad->id}", [
            'name' => 'Alta',
            'order' => 7,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.order', 7)
            ->assertJsonPath('message', 'Priority updated successfully');
    }

    public function test_no_se_borra_una_prioridad_con_compromisos(): void
    {
        $prioridad = $this->crearPrioridad('Alta', 3);
        Commitment::factory()->forTenant($this->tenant)->create(['priority_id' => $prioridad->id]);

        $this->comoAdmin()->deleteJson("/api/v1/priorities/{$prioridad->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete priority with associated commitments');

        $this->assertDatabaseHas('priorities', ['id' => $prioridad->id]);
    }

    public function test_una_prioridad_sin_compromisos_se_borra_de_verdad(): void
    {
        $prioridad = $this->crearPrioridad('Alta', 3);

        $this->comoAdmin()->deleteJson("/api/v1/priorities/{$prioridad->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Priority deleted successfully');

        // `priorities` no tiene softDeletes: el borrado es real.
        $this->assertDatabaseMissing('priorities', ['id' => $prioridad->id]);
    }

    // ==================================================================
    // Hallazgos del catálogo global
    // ==================================================================

    public function test_hallazgo_el_catalogo_es_global_y_lo_ve_cualquier_campanna(): void
    {
        // `priorities` no tiene `tenant_id`: lo que una campaña crea, renombra o
        // borra lo ven y lo sufren todas. Y la unicidad de `name` también es
        // global, así que dos campañas no pueden tener cada una su «Alta».
        $this->crearPrioridad('Alta', 3);

        $otro = Tenant::factory()->create();
        [$usuario, $token] = $this->createTenantWithUser([], $otro);

        $nombres = collect(
            $this->actingAsTenantUser($usuario, $token)->getJson('/api/v1/priorities')->json('data')
        )->pluck('name');

        $this->assertTrue($nombres->contains('Alta'), 'El catálogo de una campaña se ve desde otra.');
    }

    public function test_hallazgo_un_admin_borra_la_prioridad_que_usa_otra_campanna(): void
    {
        // La guarda de `destroy` cuenta `$priority->commitments()`, y `Commitment`
        // sí lleva `TenantScope`: los compromisos de las demás campañas quedan
        // fuera de la cuenta. El admin de una campaña borra una prioridad que
        // «no tiene compromisos» y, por el `onDelete('set null')` de la FK, deja
        // a otra campaña con sus compromisos sin prioridad.
        $prioridad = $this->crearPrioridad('Alta', 3);

        $otro = Tenant::factory()->create();
        $ajeno = Commitment::factory()->forTenant($otro)->create(['priority_id' => $prioridad->id]);

        $this->comoAdmin()->deleteJson("/api/v1/priorities/{$prioridad->id}")->assertStatus(200);

        $this->assertDatabaseMissing('priorities', ['id' => $prioridad->id]);
        $this->assertNull(
            Commitment::withoutGlobalScope(TenantScope::class)->findOrFail($ajeno->id)->priority_id,
            'El compromiso de la otra campaña se quedó sin prioridad.'
        );
    }

    // ==================================================================
    // Relación con compromisos
    // ==================================================================

    public function test_el_compromiso_expone_su_prioridad(): void
    {
        $prioridad = $this->crearPrioridad('Urgente', 4);

        [$usuario, $token] = $this->createTenantWithUser(['view_commitments'], $this->tenant);
        $compromiso = Commitment::factory()->forTenant($this->tenant)->create(['priority_id' => $prioridad->id]);

        $this->actingAsTenantUser($usuario, $token)
            ->getJson("/api/v1/commitments/{$compromiso->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.priority_id', $prioridad->id)
            ->assertJsonPath('data.priority.name', 'Urgente')
            ->assertJsonPath('data.priority.color', '#ffc107')
            ->assertJsonPath('data.priority.order', 4);

        // `PriorityResource` no publica `description`, aunque la columna exista.
        $this->assertArrayNotHasKey(
            'description',
            $this->actingAsTenantUser($usuario, $token)
                ->getJson("/api/v1/commitments/{$compromiso->id}")->json('data.priority')
        );
    }

    public function test_hallazgo_un_compromiso_en_papelera_no_protege_a_su_prioridad(): void
    {
        // Borrar un compromiso es borrado en blando y no toca la prioridad. Pero
        // la guarda de `destroy` cuenta por la relación, que excluye lo borrado
        // en blando: con el compromiso en la papelera la prioridad ya «no tiene
        // compromisos» y se puede borrar de verdad. Si alguien restaura ese
        // compromiso, vuelve sin prioridad y sin aviso.
        $prioridad = $this->crearPrioridad('Alta', 3);

        [$usuario, $token] = $this->createTenantWithUser(['delete_commitments'], $this->tenant);
        $compromiso = Commitment::factory()->forTenant($this->tenant)->create(['priority_id' => $prioridad->id]);

        $this->actingAsTenantUser($usuario, $token)
            ->deleteJson("/api/v1/commitments/{$compromiso->id}")
            ->assertStatus(200);

        $this->assertDatabaseHas('priorities', ['id' => $prioridad->id]);

        $this->comoAdmin()->deleteJson("/api/v1/priorities/{$prioridad->id}")->assertStatus(200);

        $this->assertNull(Commitment::withTrashed()->findOrFail($compromiso->id)->priority_id);
    }

    // ------------------------------------------------------------------

    private function comoUsuarioSinPermisos(): static
    {
        [$usuario, $token] = $this->createTenantWithUser([], $this->tenant);

        return $this->actingAsTenantUser($usuario, $token);
    }

    /**
     * Usuario con el rol `admin` CLONADO de este tenant (el que produce
     * `TenantProvisioningService`), no la plantilla global.
     */
    private function comoAdmin(): static
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

        $admin = User::withoutGlobalScope(TenantScope::class)
            ->where('email', 'admin@tenant-de-prueba.test')
            ->firstOrFail();

        return $this->actingAsTenantUser($admin);
    }

    private function crearPrioridad(string $nombre, int $orden): Priority
    {
        return Priority::create([
            'name' => $nombre,
            'description' => 'Prioridad de prueba',
            'color' => '#ffc107',
            'order' => $orden,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Crítica',
            'color' => '#ff0000',
            'order' => 5,
        ], $extra);
    }
}
