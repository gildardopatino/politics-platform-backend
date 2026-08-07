<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN del ciclo de vida de una reunión (Spec 0010).
 *
 * Estas pruebas fijan lo que el código hace HOY, no lo que debería hacer. Donde
 * el comportamiento resulta discutible se marca con `RAREZA:` y queda anotado en
 * `known-issues.md`; corregirlo es otra spec.
 *
 * Contrato observado en `docs/MEETINGS_API.md`.
 */
class MeetingLifecycleTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-06 09:00:00');

        // StoreMeetingRequest exige jerarquía configurada si el tenant la pide.
        $this->tenant = Tenant::factory()->create([
            'hierarchy_mode' => 'disabled',
            'require_hierarchy_config' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    public function test_index_devuelve_data_y_meta_con_paginacion(): void
    {
        Meeting::factory()->forTenant($this->tenant)->count(3)->create();

        $response = $this->comoUsuarioCon(['view_meetings'])->getJson('/api/v1/meetings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'title', 'starts_at', 'status', 'qr_code', 'attendees_count']],
                'meta' => ['total', 'current_page', 'last_page', 'per_page'],
            ])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_index_acepta_per_page(): void
    {
        Meeting::factory()->forTenant($this->tenant)->count(3)->create();

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson('/api/v1/meetings?per_page=2')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_index_filtra_por_status_y_por_titulo(): void
    {
        Meeting::factory()->forTenant($this->tenant)->create(['title' => 'Asamblea barrial', 'status' => 'scheduled']);
        Meeting::factory()->forTenant($this->tenant)->create(['title' => 'Comité técnico', 'status' => 'completed']);

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson('/api/v1/meetings?filter[status]=completed')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Comité técnico');

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson('/api/v1/meetings?filter[title]=Asamblea')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Asamblea barrial');
    }

    public function test_index_ordena_por_los_campos_permitidos(): void
    {
        Meeting::factory()->forTenant($this->tenant)->create(['title' => 'B', 'starts_at' => '2026-09-01 10:00:00']);
        Meeting::factory()->forTenant($this->tenant)->create(['title' => 'A', 'starts_at' => '2026-08-20 10:00:00']);

        $titulos = $this->comoUsuarioCon(['view_meetings'])
            ->getJson('/api/v1/meetings?sort=starts_at')
            ->assertStatus(200)
            ->json('data.*.title');

        $this->assertSame(['A', 'B'], $titulos);
    }

    public function test_index_permite_incluir_relaciones(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create();
        $meeting->attendees()->create([
            'tenant_id' => $this->tenant->id,
            'cedula' => '71000001',
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
        ]);

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson('/api/v1/meetings?include=attendees')
            ->assertStatus(200)
            ->assertJsonPath('data.0.attendees.0.cedula', '71000001');
    }

    public function test_index_exige_view_meetings(): void
    {
        $this->comoUsuarioCon([])->getJson('/api/v1/meetings')->assertStatus(403);
    }

    public function test_index_solo_muestra_reuniones_del_tenant(): void
    {
        Meeting::factory()->forTenant($this->tenant)->create();
        Meeting::factory()->forTenant(Tenant::factory()->create())->count(2)->create();

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson('/api/v1/meetings')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    // ------------------------------------------------------------------
    // store
    // ------------------------------------------------------------------

    public function test_store_crea_la_reunion_y_le_genera_un_qr(): void
    {
        $usuario = $this->usuarioCon(['create_meetings']);

        $response = $this->actingAsTenantUser($usuario)
            ->postJson('/api/v1/meetings', $this->payload($usuario));

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'title', 'qr_code', 'status'],
                'message',
                'whatsapp_notification_sent',
                'logistics_notification_sent',
            ])
            ->assertJsonPath('message', 'Meeting created successfully')
            ->assertJsonPath('whatsapp_notification_sent', false);

        // El QR se genera de forma síncrona dentro de la petición.
        $this->assertNotNull($response->json('data.qr_code'));
        $this->assertSame(32, strlen($response->json('data.qr_code')));

        $this->assertDatabaseHas('meetings', [
            'id' => $response->json('data.id'),
            'tenant_id' => $this->tenant->id,
            'title' => 'Asamblea de prueba',
        ]);
    }

    public function test_rareza_la_respuesta_de_store_devuelve_status_null(): void
    {
        // `status` no viaja en el payload: lo pone el DEFAULT de la columna. Como
        // el controller no recarga el modelo tras crearlo, el atributo no está en
        // memoria y el Resource lo serializa como null, aunque en la base valga
        // 'scheduled'. El frontend que se fíe de `data.status` tras crear se
        // encuentra un null.
        $usuario = $this->usuarioCon(['create_meetings']);

        $response = $this->actingAsTenantUser($usuario)
            ->postJson('/api/v1/meetings', $this->payload($usuario));

        $response->assertStatus(201)->assertJsonPath('data.status', null);

        $this->assertDatabaseHas('meetings', [
            'id' => $response->json('data.id'),
            'status' => 'scheduled',
        ]);
    }

    public function test_store_valida_los_campos_obligatorios(): void
    {
        $this->comoUsuarioCon(['create_meetings'])
            ->postJson('/api/v1/meetings', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'starts_at', 'planner_user_id']);
    }

    public function test_store_exige_que_ends_at_sea_posterior_a_starts_at(): void
    {
        $usuario = $this->usuarioCon(['create_meetings']);

        $this->actingAsTenantUser($usuario)
            ->postJson('/api/v1/meetings', $this->payload($usuario, [
                'starts_at' => '2026-09-01 10:00:00',
                'ends_at' => '2026-09-01 09:00:00',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ends_at']);
    }

    public function test_store_exige_create_meetings(): void
    {
        $usuario = $this->usuarioCon(['view_meetings']);

        $this->actingAsTenantUser($usuario)
            ->postJson('/api/v1/meetings', $this->payload($usuario))
            ->assertStatus(403);
    }

    public function test_store_ignora_el_tenant_id_que_venga_en_el_payload(): void
    {
        // El controller fuerza el tenant del usuario autenticado.
        $otro = Tenant::factory()->create();
        $usuario = $this->usuarioCon(['create_meetings']);

        $id = $this->actingAsTenantUser($usuario)
            ->postJson('/api/v1/meetings', $this->payload($usuario, ['tenant_id' => $otro->id]))
            ->assertStatus(201)
            ->json('data.id');

        $this->assertDatabaseHas('meetings', ['id' => $id, 'tenant_id' => $this->tenant->id]);
    }

    // ------------------------------------------------------------------
    // show / update / destroy
    // ------------------------------------------------------------------

    public function test_show_devuelve_la_reunion_con_sus_relaciones(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create();

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson("/api/v1/meetings/{$meeting->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $meeting->id)
            ->assertJsonStructure(['data' => ['id', 'title', 'planner', 'attendees', 'commitments']]);
    }

    public function test_show_exige_view_meetings(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create();

        $this->comoUsuarioCon([])->getJson("/api/v1/meetings/{$meeting->id}")->assertStatus(403);
    }

    public function test_update_modifica_solo_lo_enviado(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create([
            'title' => 'Título viejo',
            'lugar_nombre' => 'Salón A',
        ]);

        $this->comoUsuarioCon(['edit_meetings'])
            ->putJson("/api/v1/meetings/{$meeting->id}", ['title' => 'Título nuevo'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Título nuevo')
            ->assertJsonPath('data.lugar_nombre', 'Salón A')
            ->assertJsonPath('message', 'Meeting updated successfully');
    }

    public function test_update_exige_edit_meetings(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create();

        $this->comoUsuarioCon(['view_meetings'])
            ->putJson("/api/v1/meetings/{$meeting->id}", ['title' => 'X'])
            ->assertStatus(403);
    }

    public function test_destroy_borra_en_blando(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create();

        $this->comoUsuarioCon(['delete_meetings'])
            ->deleteJson("/api/v1/meetings/{$meeting->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Meeting deleted successfully');

        $this->assertSoftDeleted('meetings', ['id' => $meeting->id]);
    }

    // ------------------------------------------------------------------
    // complete / cancel
    // ------------------------------------------------------------------

    public function test_complete_marca_la_reunion_y_le_pone_ends_at_ahora(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create(['status' => 'scheduled']);

        $this->comoUsuarioCon(['edit_meetings'])
            ->postJson("/api/v1/meetings/{$meeting->id}/complete")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('message', 'Meeting marked as completed');

        $this->assertSame('2026-08-06 09:00:00', $meeting->fresh()->ends_at->toDateTimeString());
    }

    public function test_cancel_marca_la_reunion_como_cancelada(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create(['status' => 'scheduled']);

        $this->comoUsuarioCon(['edit_meetings'])
            ->postJson("/api/v1/meetings/{$meeting->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('message', 'Meeting cancelled');
    }

    public function test_rareza_complete_y_cancel_no_validan_el_estado_previo(): void
    {
        // Se puede completar una reunión ya cancelada, y cancelar una completada,
        // tantas veces como se quiera: no hay máquina de estados.
        $meeting = Meeting::factory()->forTenant($this->tenant)->create(['status' => 'cancelled']);

        $this->comoUsuarioCon(['edit_meetings'])
            ->postJson("/api/v1/meetings/{$meeting->id}/complete")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $this->comoUsuarioCon(['edit_meetings'])
            ->postJson("/api/v1/meetings/{$meeting->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_complete_y_cancel_exigen_edit_meetings(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create();

        $this->comoUsuarioCon(['view_meetings'])
            ->postJson("/api/v1/meetings/{$meeting->id}/complete")
            ->assertStatus(403);

        $this->comoUsuarioCon(['view_meetings'])
            ->postJson("/api/v1/meetings/{$meeting->id}/cancel")
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // getQRCode
    // ------------------------------------------------------------------

    public function test_qr_code_devuelve_el_svg_y_la_url_de_check_in(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-CARACTERIZACION']);

        $response = $this->comoUsuarioCon(['view_meetings'])
            ->getJson("/api/v1/meetings/{$meeting->id}/qr-code");

        $response->assertStatus(200)
            ->assertJsonStructure(['qr_code', 'qr_url', 'check_in_url', 'svg', 'svg_base64'])
            ->assertJsonPath('qr_code', 'QR-CARACTERIZACION');

        $this->assertStringContainsString('QR-CARACTERIZACION', $response->json('check_in_url'));

        // RAREZA: `svg` no llega como string. QrCode::generate() devuelve un
        // HtmlString y al serializar a JSON sale como objeto, así que el cliente
        // no puede pintarlo directamente. El campo utilizable es `svg_base64`.
        $this->assertIsArray($response->json('svg'));
        $this->assertIsString($response->json('svg_base64'));
        $this->assertStringContainsString('<svg', base64_decode($response->json('svg_base64')));
    }

    public function test_qr_code_devuelve_404_si_la_reunion_no_tiene_codigo(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => null]);

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson("/api/v1/meetings/{$meeting->id}/qr-code")
            ->assertStatus(404)
            ->assertJsonPath('message', 'QR code not generated yet');
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
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(User $planner, array $extra = []): array
    {
        return array_merge([
            'title' => 'Asamblea de prueba',
            'starts_at' => '2026-09-01 10:00:00',
            'planner_user_id' => $planner->id,
            'lugar_nombre' => 'Salón comunal',
        ], $extra);
    }
}
