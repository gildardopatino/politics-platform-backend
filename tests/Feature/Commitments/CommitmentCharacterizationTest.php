<?php

namespace Tests\Feature\Commitments;

use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\Priority;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN del CRUD de compromisos y sus acciones (Spec 0012, Fase 1).
 *
 * Fija el comportamiento actual de `CommitmentController`: index (filtros,
 * includes, orden, paginación), store, show, update, destroy, complete y
 * byMeeting, más permisos y aislamiento entre tenants. `overdue` ya estaba
 * cubierto por `CommitmentOverdueTest` (Spec 0001); aquí solo se añade el matiz
 * de la frontera del día.
 *
 * Lo que está mal se fija igual y se marca `test_hallazgo_*`: la caracterización
 * documenta, no corrige. Contrato en `docs/COMMITMENTS_API.md`.
 *
 * El encolado de recordatorios se caracteriza aparte, en
 * `CommitmentRemindersCharacterizationTest`. Aquí la cola va en falso para que
 * `store` no salga a la red.
 */
class CommitmentCharacterizationTest extends TestCase
{
    private Tenant $tenant;

    private User $user;

    private string $token;

    private Meeting $meeting;

    private Priority $prioridad;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 12:00:00');

        Queue::fake();
        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();

        [$this->user, $this->token] = $this->createTenantWithUser([
            'view_commitments', 'create_commitments', 'edit_commitments', 'delete_commitments',
        ], $this->tenant);

        $this->meeting = Meeting::factory()->forTenant($this->tenant)->create(['title' => 'Asamblea barrial']);
        $this->prioridad = $this->crearPrioridad('Alta', 3);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ==================================================================
    // index
    // ==================================================================

    public function test_index_pagina_y_solo_trae_los_del_tenant(): void
    {
        $propio = $this->crearCompromiso(['description' => 'Del tenant']);
        $ajeno = Commitment::factory()->forTenant(Tenant::factory()->create())->create();

        $respuesta = $this->comoUsuario()->getJson('/api/v1/commitments');

        $respuesta->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'tenant_id', 'meeting_id', 'description', 'due_date', 'status', 'notes']],
                'meta' => ['total', 'current_page', 'last_page', 'per_page'],
            ]);

        $ids = collect($respuesta->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($propio->id));
        $this->assertFalse($ids->contains($ajeno->id));
        $this->assertSame(15, $respuesta->json('meta.per_page'));
    }

    public function test_index_filtra_por_status_reunion_asignado_y_prioridad(): void
    {
        $otraReunion = Meeting::factory()->forTenant($this->tenant)->create();
        $otraPrioridad = $this->crearPrioridad('Baja', 1);

        $buscado = $this->crearCompromiso([
            'status' => 'in_progress',
            'assigned_user_id' => $this->user->id,
        ]);
        $descartado = $this->crearCompromiso([
            'status' => 'pending',
            'meeting_id' => $otraReunion->id,
            'priority_id' => $otraPrioridad->id,
        ]);

        foreach ([
            'filter[status]=in_progress',
            "filter[meeting_id]={$this->meeting->id}",
            "filter[assigned_user_id]={$this->user->id}",
            "filter[priority_id]={$this->prioridad->id}",
        ] as $filtro) {
            $ids = collect($this->comoUsuario()->getJson("/api/v1/commitments?{$filtro}")->json('data'))->pluck('id');

            $this->assertTrue($ids->contains($buscado->id), "El filtro {$filtro} debería traer el compromiso buscado.");
            $this->assertFalse($ids->contains($descartado->id), "El filtro {$filtro} no debería traer el descartado.");
        }
    }

    public function test_index_carga_siempre_reunion_asignado_y_prioridad(): void
    {
        $this->crearCompromiso(['assigned_user_id' => $this->user->id]);

        // El controller hace `with([...])` fijo, así que las tres relaciones
        // viajan sin pedirlas. `createdBy` no: hay que incluirlo.
        $fila = $this->comoUsuario()->getJson('/api/v1/commitments')->json('data.0');

        $this->assertArrayHasKey('meeting', $fila);
        $this->assertArrayHasKey('assigned_user', $fila);
        $this->assertArrayHasKey('priority', $fila);
        $this->assertArrayNotHasKey('creator', $fila);

        $conCreador = $this->comoUsuario()->getJson('/api/v1/commitments?include=createdBy')->json('data.0');

        $this->assertArrayHasKey('creator', $conCreador);
    }

    public function test_index_ordena_por_fecha_de_vencimiento(): void
    {
        $tarde = $this->crearCompromiso(['due_date' => '2026-09-30']);
        $pronto = $this->crearCompromiso(['due_date' => '2026-08-20']);

        $ids = collect($this->comoUsuario()->getJson('/api/v1/commitments?sort=due_date')->json('data'))->pluck('id');

        $this->assertSame([$pronto->id, $tarde->id], $ids->all());
    }

    public function test_index_respeta_per_page(): void
    {
        Commitment::factory()->forTenant($this->tenant)->count(3)->create();

        $respuesta = $this->comoUsuario()->getJson('/api/v1/commitments?per_page=2');

        $this->assertCount(2, $respuesta->json('data'));
        $this->assertSame(3, $respuesta->json('meta.total'));
        $this->assertSame(2, $respuesta->json('meta.last_page'));
    }

    // ==================================================================
    // store
    // ==================================================================

    public function test_store_crea_el_compromiso_con_el_tenant_y_el_autor_de_la_sesion(): void
    {
        $respuesta = $this->comoUsuario()->postJson('/api/v1/commitments', $this->payload());

        $respuesta->assertStatus(201)
            ->assertJsonPath('message', 'Commitment created successfully')
            ->assertJsonPath('data.description', 'Llevar el acta a la junta')
            ->assertJsonPath('data.due_date', '2026-08-27');

        $this->assertDatabaseHas('commitments', [
            'id' => $respuesta->json('data.id'),
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => 'pending',
        ]);
    }

    public function test_hallazgo_store_responde_status_nulo_aunque_en_la_base_valga_pending(): void
    {
        // Misma clase de bug que cerró la Spec 0021 en meetings y asistentes
        // (hallazgo F5): `status` lo pone el DEFAULT de la columna y el
        // controller no recarga el modelo antes de serializarlo, así que la
        // respuesta de creación dice `null` y el cliente no distingue «pendiente»
        // de «no se sabe». Aquel arreglo (`->refresh()`) no llegó a compromisos.
        $respuesta = $this->comoUsuario()->postJson('/api/v1/commitments', $this->payload());

        $respuesta->assertStatus(201)->assertJsonPath('data.status', null);

        $this->assertSame('pending', Commitment::findOrFail($respuesta->json('data.id'))->status);
    }

    public function test_store_informa_si_disparo_la_notificacion_de_whatsapp(): void
    {
        // La clave `whatsapp_notification_sent` no dice que se haya enviado nada:
        // solo que se encoló el aviso de asignación (hace falta teléfono).
        $sinTelefono = User::factory()->forTenant($this->tenant)->create(['phone' => null]);

        $this->comoUsuario()
            ->postJson('/api/v1/commitments', $this->payload(['assigned_user_id' => $sinTelefono->id]))
            ->assertStatus(201)
            ->assertJsonPath('whatsapp_notification_sent', false);

        $conTelefono = User::factory()->forTenant($this->tenant)->create(['phone' => '3001234567']);

        $this->comoUsuario()
            ->postJson('/api/v1/commitments', $this->payload(['assigned_user_id' => $conTelefono->id]))
            ->assertStatus(201)
            ->assertJsonPath('whatsapp_notification_sent', true);
    }

    public function test_store_exige_reunion_asignado_prioridad_descripcion_y_fecha(): void
    {
        $respuesta = $this->comoUsuario()->postJson('/api/v1/commitments', []);

        $respuesta->assertStatus(422)->assertJsonValidationErrors([
            'meeting_id', 'assigned_user_id', 'priority_id', 'description', 'due_date',
        ]);

        // Los mensajes ya están en español (Art. IX).
        $this->assertSame('La descripción del compromiso es obligatoria', $respuesta->json('errors.description.0'));
        $this->assertSame('Debe asignar el compromiso a un usuario', $respuesta->json('errors.assigned_user_id.0'));
    }

    public function test_store_rechaza_referencias_inexistentes(): void
    {
        $this->comoUsuario()->postJson('/api/v1/commitments', $this->payload([
            'meeting_id' => 99999,
            'priority_id' => 99999,
        ]))->assertStatus(422)->assertJsonValidationErrors(['meeting_id', 'priority_id']);
    }

    public function test_hallazgo_store_acepta_reuniones_y_usuarios_de_otro_tenant(): void
    {
        // `exists:meetings,id` y `exists:users,id` consultan la tabla en crudo,
        // sin `TenantScope`: se puede colgar un compromiso de la reunión de otra
        // campaña y asignárselo a alguien ajeno. No se filtran datos —las
        // relaciones se cargan vacías por el scope— pero el vínculo queda
        // escrito. Mismo patrón que `CallController@store` (0011).
        $otro = Tenant::factory()->create();
        $reunionAjena = Meeting::factory()->forTenant($otro)->create();
        $usuarioAjeno = User::factory()->forTenant($otro)->create();

        $respuesta = $this->comoUsuario()->postJson('/api/v1/commitments', $this->payload([
            'meeting_id' => $reunionAjena->id,
            'assigned_user_id' => $usuarioAjeno->id,
        ]));

        $respuesta->assertStatus(201);

        $this->assertDatabaseHas('commitments', [
            'id' => $respuesta->json('data.id'),
            'tenant_id' => $this->tenant->id,
            'meeting_id' => $reunionAjena->id,
            'assigned_user_id' => $usuarioAjeno->id,
        ]);

        // La respuesta no revela nada del otro tenant: el scope vacía las
        // relaciones.
        $this->assertNull($respuesta->json('data.meeting'));
        $this->assertNull($respuesta->json('data.assigned_user'));
    }

    public function test_hallazgo_store_admite_un_status_que_la_columna_rechaza(): void
    {
        // `StoreCommitmentRequest` admite `scheduled`; el enum de la columna solo
        // tiene pending, in_progress, completed y cancelled. Pasa el validador y
        // revienta al insertar.
        $this->comoUsuario()
            ->postJson('/api/v1/commitments', $this->payload(['status' => 'scheduled']))
            ->assertStatus(500);

        $this->assertDatabaseMissing('commitments', ['description' => 'Llevar el acta a la junta']);
    }

    // ==================================================================
    // show / update / destroy
    // ==================================================================

    public function test_show_devuelve_el_compromiso_con_sus_relaciones(): void
    {
        $compromiso = $this->crearCompromiso(['assigned_user_id' => $this->user->id]);

        $this->comoUsuario()->getJson("/api/v1/commitments/{$compromiso->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $compromiso->id)
            ->assertJsonPath('data.meeting.id', $this->meeting->id)
            ->assertJsonPath('data.assigned_user.id', $this->user->id)
            ->assertJsonPath('data.priority.id', $this->prioridad->id)
            ->assertJsonPath('data.creator.id', $this->user->id);
    }

    public function test_update_cambia_solo_lo_enviado(): void
    {
        $compromiso = $this->crearCompromiso(['notes' => 'Nota original']);

        $this->comoUsuario()
            ->putJson("/api/v1/commitments/{$compromiso->id}", ['status' => 'in_progress'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.notes', 'Nota original')
            ->assertJsonPath('message', 'Commitment updated successfully');
    }

    public function test_hallazgo_update_admite_dos_status_que_la_columna_rechaza(): void
    {
        // `UpdateCommitmentRequest` admite `scheduled` y `no_conmpleted` —con la
        // errata incluida—, ninguno en el enum de la columna.
        $compromiso = $this->crearCompromiso();

        foreach (['scheduled', 'no_conmpleted'] as $status) {
            $this->comoUsuario()
                ->putJson("/api/v1/commitments/{$compromiso->id}", ['status' => $status])
                ->assertStatus(500);
        }

        $this->assertSame('pending', $compromiso->fresh()->status);
    }

    public function test_destroy_borra_en_blando(): void
    {
        $compromiso = $this->crearCompromiso();

        $this->comoUsuario()->deleteJson("/api/v1/commitments/{$compromiso->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Commitment deleted successfully');

        $this->assertSoftDeleted('commitments', ['id' => $compromiso->id]);

        $this->comoUsuario()->getJson("/api/v1/commitments/{$compromiso->id}")->assertStatus(404);
    }

    // ==================================================================
    // complete
    // ==================================================================

    public function test_complete_marca_el_compromiso_como_completado(): void
    {
        $compromiso = $this->crearCompromiso();

        $this->comoUsuario()->postJson("/api/v1/commitments/{$compromiso->id}/complete")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('message', 'Commitment marked as completed');

        $this->assertSame('completed', $compromiso->fresh()->status);
    }

    public function test_hallazgo_complete_intenta_escribir_una_columna_que_no_existe(): void
    {
        // `complete()` hace `update(['status' => ..., 'fecha_cumplimiento' => now()])`.
        // `fecha_cumplimiento` no está en `$fillable` ni existe como columna, así
        // que el asignamiento masivo lo descarta **en silencio**: no queda
        // constancia de CUÁNDO se cumplió el compromiso.
        $compromiso = $this->crearCompromiso();

        $this->comoUsuario()->postJson("/api/v1/commitments/{$compromiso->id}/complete")
            ->assertStatus(200);

        $this->assertArrayNotHasKey('fecha_cumplimiento', $compromiso->fresh()->getAttributes());
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('commitments', 'fecha_cumplimiento'),
            'La columna no existe: el dato de cumplimiento se pierde.'
        );
    }

    public function test_hallazgo_complete_responde_sin_las_relaciones(): void
    {
        // A diferencia de store/update/show, `complete` no hace `load()`: la
        // respuesta sale sin `meeting`, `assigned_user` ni `priority`, así que el
        // cliente recibe una forma distinta para el mismo recurso.
        $compromiso = $this->crearCompromiso(['assigned_user_id' => $this->user->id]);

        $data = $this->comoUsuario()->postJson("/api/v1/commitments/{$compromiso->id}/complete")->json('data');

        $this->assertArrayNotHasKey('meeting', $data);
        $this->assertArrayNotHasKey('assigned_user', $data);
        $this->assertArrayNotHasKey('priority', $data);
    }

    public function test_complete_es_idempotente_y_no_mira_el_estado_previo(): void
    {
        // Se puede «completar» un compromiso cancelado o ya completado, tantas
        // veces como se llame. No hay máquina de estados.
        $compromiso = $this->crearCompromiso(['status' => 'cancelled']);

        $this->comoUsuario()->postJson("/api/v1/commitments/{$compromiso->id}/complete")->assertStatus(200);
        $this->comoUsuario()->postJson("/api/v1/commitments/{$compromiso->id}/complete")->assertStatus(200);

        $this->assertSame('completed', $compromiso->fresh()->status);
    }

    // ==================================================================
    // byMeeting / overdue
    // ==================================================================

    public function test_by_meeting_solo_trae_los_de_esa_reunion(): void
    {
        $otraReunion = Meeting::factory()->forTenant($this->tenant)->create();

        $deLaReunion = $this->crearCompromiso();
        $deOtra = $this->crearCompromiso(['meeting_id' => $otraReunion->id]);

        $respuesta = $this->comoUsuario()->getJson("/api/v1/meetings/{$this->meeting->id}/commitments");

        $respuesta->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['total', 'current_page', 'last_page', 'per_page']]);

        $ids = collect($respuesta->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($deLaReunion->id));
        $this->assertFalse($ids->contains($deOtra->id));
    }

    public function test_by_meeting_con_la_reunion_de_otro_tenant_da_404(): void
    {
        $ajena = Meeting::factory()->forTenant(Tenant::factory()->create())->create();

        $this->comoUsuario()->getJson("/api/v1/meetings/{$ajena->id}/commitments")->assertStatus(404);
    }

    public function test_hallazgo_overdue_considera_vencido_lo_que_vence_hoy(): void
    {
        // `due_date` es una fecha sin hora, así que se compara como las 00:00 del
        // día contra `now()`. A cualquier hora del día de vencimiento el
        // compromiso ya aparece como vencido.
        $venceHoy = $this->crearCompromiso(['due_date' => '2026-08-07', 'status' => 'pending']);

        $ids = collect($this->comoUsuario()->getJson('/api/v1/commitments/overdue')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($venceHoy->id));
    }

    // ==================================================================
    // Permisos y aislamiento
    // ==================================================================

    public function test_cada_verbo_exige_su_permiso(): void
    {
        $compromiso = $this->crearCompromiso();
        [$pelado, $tokenPelado] = $this->createTenantWithUser([], $this->tenant);

        $sesion = fn () => $this->actingAsTenantUser($pelado, $tokenPelado);

        $sesion()->getJson('/api/v1/commitments')->assertStatus(403);
        $sesion()->getJson('/api/v1/commitments/overdue')->assertStatus(403);
        $sesion()->getJson("/api/v1/meetings/{$this->meeting->id}/commitments")->assertStatus(403);
        $sesion()->getJson("/api/v1/commitments/{$compromiso->id}")->assertStatus(403);
        $sesion()->postJson('/api/v1/commitments', $this->payload())->assertStatus(403);
        $sesion()->putJson("/api/v1/commitments/{$compromiso->id}", ['status' => 'in_progress'])->assertStatus(403);
        $sesion()->postJson("/api/v1/commitments/{$compromiso->id}/complete")->assertStatus(403);
        $sesion()->deleteJson("/api/v1/commitments/{$compromiso->id}")->assertStatus(403);
    }

    public function test_sin_sesion_todo_el_modulo_responde_401(): void
    {
        $compromiso = $this->crearCompromiso();

        $this->getJson('/api/v1/commitments')->assertStatus(401);
        $this->getJson("/api/v1/commitments/{$compromiso->id}")->assertStatus(401);
        $this->postJson('/api/v1/commitments', $this->payload())->assertStatus(401);
        $this->postJson("/api/v1/commitments/{$compromiso->id}/complete")->assertStatus(401);
    }

    public function test_un_compromiso_de_otro_tenant_da_404_en_show_update_complete_y_destroy(): void
    {
        $ajeno = Commitment::factory()->forTenant(Tenant::factory()->create())->create();

        $this->comoUsuario()->getJson("/api/v1/commitments/{$ajeno->id}")->assertStatus(404);
        $this->comoUsuario()->putJson("/api/v1/commitments/{$ajeno->id}", ['status' => 'in_progress'])->assertStatus(404);
        $this->comoUsuario()->postJson("/api/v1/commitments/{$ajeno->id}/complete")->assertStatus(404);
        $this->comoUsuario()->deleteJson("/api/v1/commitments/{$ajeno->id}")->assertStatus(404);

        $this->assertSame('pending', $ajeno->fresh()->status);
    }

    // ------------------------------------------------------------------

    private function comoUsuario(): static
    {
        return $this->actingAsTenantUser($this->user, $this->token);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function crearCompromiso(array $atributos = []): Commitment
    {
        return Commitment::factory()->forTenant($this->tenant)->create(array_merge([
            'meeting_id' => $this->meeting->id,
            'priority_id' => $this->prioridad->id,
            'created_by' => $this->user->id,
        ], $atributos));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'meeting_id' => $this->meeting->id,
            'assigned_user_id' => $this->user->id,
            'priority_id' => $this->prioridad->id,
            'description' => 'Llevar el acta a la junta',
            'due_date' => '2026-08-27',
        ], $extra);
    }

    private function crearPrioridad(string $nombre, int $orden): Priority
    {
        // `priorities` es un catálogo GLOBAL: no tiene tenant_id ni HasTenant.
        return Priority::create([
            'name' => $nombre,
            'color' => '#fd7e14',
            'order' => $orden,
        ]);
    }
}
