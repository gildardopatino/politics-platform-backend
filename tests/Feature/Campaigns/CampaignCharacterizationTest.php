<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN del CRUD de campañas y sus acciones (Spec 0013, Fase 1).
 *
 * Fija `CampaignController`: index (filtros, includes, orden), store, show,
 * update, destroy, send, cancel y recipients, más permisos y aislamiento entre
 * tenants.
 *
 * El vocabulario de estados es el nudo del módulo: la columna admite
 * `draft, pending, scheduled, sending, sent, failed`, pero el controller
 * pregunta por `in_progress` y `completed` —que nadie escribe nunca— y `cancel`
 * intenta escribir `cancelled`, que no está en el enum. Eso es lo que fijan
 * varios `test_hallazgo_*`.
 *
 * El encolado y el envío se caracterizan aparte, en
 * `CampaignSendCharacterizationTest`. Aquí la cola va en falso para que `store`
 * no salga a la red.
 */
class CampaignCharacterizationTest extends TestCase
{
    private Tenant $tenant;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 12:00:00');

        Queue::fake();
        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();

        [$this->user, $this->token] = $this->createTenantWithUser([
            'view_campaigns', 'create_campaigns', 'edit_campaigns', 'delete_campaigns',
        ], $this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ==================================================================
    // index
    // ==================================================================

    public function test_index_pagina_y_solo_trae_las_del_tenant(): void
    {
        $propia = Campaign::factory()->forTenant($this->tenant)->create(['title' => 'De la campaña']);
        $ajena = Campaign::factory()->forTenant(Tenant::factory()->create())->create();

        $respuesta = $this->comoUsuario()->getJson('/api/v1/campaigns');

        $respuesta->assertStatus(200)->assertJsonStructure([
            'data' => [[
                'id', 'tenant_id', 'title', 'message', 'channel', 'status',
                'total_recipients', 'sent_count', 'failed_count', 'progress_percentage',
            ]],
            'meta' => ['total', 'current_page', 'last_page', 'per_page'],
        ]);

        $ids = collect($respuesta->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($propia->id));
        $this->assertFalse($ids->contains($ajena->id));
        $this->assertSame(15, $respuesta->json('meta.per_page'));
    }

    public function test_index_filtra_por_estado_y_canal(): void
    {
        $buscada = Campaign::factory()->forTenant($this->tenant)->sent()->create(['channel' => 'email']);
        $otra = Campaign::factory()->forTenant($this->tenant)->create(['channel' => 'whatsapp']);

        foreach (['filter[status]=sent', 'filter[channel]=email'] as $filtro) {
            $ids = collect($this->comoUsuario()->getJson("/api/v1/campaigns?{$filtro}")->json('data'))->pluck('id');

            $this->assertTrue($ids->contains($buscada->id), "El filtro {$filtro} debería traerla.");
            $this->assertFalse($ids->contains($otra->id), "El filtro {$filtro} no debería traer la otra.");
        }
    }

    public function test_hallazgo_el_filtro_y_el_orden_por_titulo_apuntan_a_una_columna_inexistente(): void
    {
        // `allowedFilters`/`allowedSorts` declaran `titulo`, pero la columna se
        // llama `title`. Filtrar por el nombre publicado revienta contra la base.
        Campaign::factory()->forTenant($this->tenant)->create(['title' => 'Zeta']);
        Campaign::factory()->forTenant($this->tenant)->create(['title' => 'Alfa']);

        $this->comoUsuario()->getJson('/api/v1/campaigns?filter[titulo]=algo')->assertStatus(500);

        // El orden por ese mismo nombre NO ordena. Aquí responde 200 por una
        // rareza de SQLite —`order by "titulo"` se interpreta como una constante
        // de texto cuando no hay columna así—, de modo que las filas salen en el
        // orden de inserción. **En PostgreSQL sería un error de columna
        // inexistente**, igual que el filtro. Se fija lo observable sin
        // pretender que este 200 sea el comportamiento de producción.
        $titulos = collect(
            $this->comoUsuario()->getJson('/api/v1/campaigns?sort=titulo')->json('data')
        )->pluck('title');

        $this->assertSame(['Zeta', 'Alfa'], $titulos->all(), 'No ordena por título.');

        // El orden por las columnas que sí existen funciona.
        $this->comoUsuario()->getJson('/api/v1/campaigns?sort=-created_at')->assertStatus(200);
    }

    public function test_index_admite_incluir_el_creador_y_los_destinatarios(): void
    {
        $campana = Campaign::factory()->forTenant($this->tenant)->create();
        $this->crearDestinatario($campana, 'ana@ejemplo.test');

        $fila = $this->comoUsuario()->getJson('/api/v1/campaigns')->json('data.0');

        $this->assertArrayNotHasKey('creator', $fila);
        $this->assertArrayNotHasKey('recipients', $fila);

        $conIncludes = $this->comoUsuario()
            ->getJson('/api/v1/campaigns?include=createdBy,recipients')->json('data.0');

        $this->assertArrayHasKey('creator', $conIncludes);
        $this->assertSame('ana@ejemplo.test', $conIncludes['recipients'][0]['recipient_value']);
    }

    // ==================================================================
    // store
    // ==================================================================

    public function test_store_crea_la_campanna_en_pendiente_y_cuenta_sus_destinatarios(): void
    {
        // Sin `filter_json` el objetivo por defecto es `all_users`: todos los
        // usuarios del tenant con el dato que pide el canal.
        User::factory()->forTenant($this->tenant)->create(['phone' => '3001112233']);

        $respuesta = $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload());

        $respuesta->assertStatus(201)
            ->assertJsonPath('message', 'Campaign created and queued for sending')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.title', 'Invitación a la asamblea')
            ->assertJsonPath('data.channel', 'whatsapp')
            ->assertJsonPath('data.sent_count', 0)
            ->assertJsonPath('data.progress_percentage', 0);

        $this->assertSame(1, $respuesta->json('data.total_recipients'));

        $this->assertDatabaseHas('campaigns', [
            'id' => $respuesta->json('data.id'),
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_store_con_fecha_programada_la_deja_en_scheduled(): void
    {
        $respuesta = $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload([
            'scheduled_at' => '2026-08-20 09:00:00',
        ]));

        $respuesta->assertStatus(201)->assertJsonPath('data.status', 'scheduled');

        $this->assertNotNull($respuesta->json('data.scheduled_at'));
    }

    public function test_store_valida_titulo_mensaje_canal_y_fecha(): void
    {
        $this->comoUsuario()->postJson('/api/v1/campaigns', [])
            ->assertStatus(422)->assertJsonValidationErrors(['title', 'message', 'channel']);

        $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload(['channel' => 'paloma']))
            ->assertStatus(422)->assertJsonValidationErrors(['channel']);

        $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload([
            'filter_json' => ['target' => 'inventado'],
        ]))->assertStatus(422)->assertJsonValidationErrors(['filter_json.target']);

        // La fecha programada tiene que ser futura, y el mensaje ya va en español.
        $respuesta = $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload([
            'scheduled_at' => '2026-08-01 09:00:00',
        ]));

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['scheduled_at']);
        $this->assertSame(
            'La fecha programada debe ser posterior a la fecha actual.',
            $respuesta->json('errors.scheduled_at.0')
        );
    }

    public function test_hallazgo_el_alta_guarda_un_jwt_de_un_anno_en_la_base(): void
    {
        // `CampaignService` genera un token del creador con TTL de un año y lo
        // guarda en claro en `campaigns.creator_token` para que el webhook de
        // correo lo reutilice. Es una credencial de larga duración persistida:
        // quien lea la tabla actúa como esa persona durante un año.
        $id = $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload())->json('data.id');

        $token = DB::table('campaigns')->where('id', $id)->value('creator_token');

        $this->assertNotEmpty($token);
        $this->assertSame(3, substr_count($token, '.') + 1, 'Es un JWT de tres partes.');
    }

    public function test_ese_jwt_ya_no_entra_en_la_auditoria(): void
    {
        // Era el hallazgo: `Campaign` es `Auditable` y no declaraba
        // `$auditExclude`, así que el token viajaba también al registro de
        // auditoría —consultable por API con `view_audits`— y la credencial se
        // multiplicaba. Cerrado por la Spec 0039; el detalle vive en
        // `CampaignCreatorTokenTest`.
        //
        // La comprobación va sobre `toAudit()` y no sobre la tabla `audits`
        // porque `owen-it` se desactiva cuando la app corre en consola
        // (`config('audit.console') === false`), que es justo el caso de la
        // suite. Lo que se fija es qué campos lleva el registro, no si el driver
        // está encendido.
        $id = $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload())->json('data.id');

        $campana = Campaign::findOrFail($id);
        $campana->setAuditEvent('created');

        $registro = $campana->toAudit()['new_values'];

        $this->assertArrayHasKey('title', $registro, 'La auditoría sigue registrando lo demás.');
        $this->assertArrayNotHasKey('creator_token', $registro);
    }

    public function test_la_respuesta_nunca_publica_el_token_del_creador(): void
    {
        // `CampaignResource` sí lo omite: la fuga es de base de datos, no de API.
        $data = $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload())->json('data');

        $this->assertArrayNotHasKey('creator_token', $data);
    }

    // ==================================================================
    // show / update / destroy
    // ==================================================================

    public function test_show_carga_el_creador_y_los_destinatarios(): void
    {
        $campana = Campaign::factory()->forTenant($this->tenant)->create(['created_by' => $this->user->id]);
        $this->crearDestinatario($campana, 'ana@ejemplo.test');

        $this->comoUsuario()->getJson("/api/v1/campaigns/{$campana->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $campana->id)
            ->assertJsonPath('data.creator.id', $this->user->id)
            ->assertJsonPath('data.recipients.0.recipient_value', 'ana@ejemplo.test');
    }

    public function test_update_solo_admite_campannas_pendientes(): void
    {
        $pendiente = Campaign::factory()->forTenant($this->tenant)->create();

        $this->comoUsuario()->putJson("/api/v1/campaigns/{$pendiente->id}", ['title' => 'Nuevo título'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Nuevo título')
            ->assertJsonPath('message', 'Campaign updated successfully');

        $enviada = Campaign::factory()->forTenant($this->tenant)->sent()->create();

        $this->comoUsuario()->putJson("/api/v1/campaigns/{$enviada->id}", ['title' => 'Tarde'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot update campaign that is not pending');
    }

    public function test_hallazgo_una_campanna_programada_no_se_puede_editar(): void
    {
        // El guardián exige `pending`, y una campaña con fecha nace `scheduled`:
        // lo único que todavía NO se ha enviado es justo lo que no se puede
        // corregir. Para cambiarle el texto hay que borrarla y rehacerla.
        $programada = Campaign::factory()->forTenant($this->tenant)->scheduled()->create();

        $this->comoUsuario()->putJson("/api/v1/campaigns/{$programada->id}", ['title' => 'Corregido'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot update campaign that is not pending');
    }

    public function test_hallazgo_editar_no_regenera_los_destinatarios(): void
    {
        // Los destinatarios se resuelven una sola vez, al crear. Cambiar el canal
        // o el filtro deja la lista vieja —y `total_recipients` desactualizado—
        // sin ningún aviso.
        $campana = Campaign::factory()->forTenant($this->tenant)->create([
            'channel' => 'whatsapp',
            'total_recipients' => 1,
        ]);
        $this->crearDestinatario($campana, '3001112233', 'whatsapp');

        $this->comoUsuario()->putJson("/api/v1/campaigns/{$campana->id}", ['channel' => 'email'])
            ->assertStatus(200);

        $this->assertSame(1, $campana->fresh()->total_recipients);
        $this->assertSame('whatsapp', CampaignRecipient::firstOrFail()->recipient_type);
    }

    public function test_destroy_borra_en_blando(): void
    {
        $campana = Campaign::factory()->forTenant($this->tenant)->create();

        $this->comoUsuario()->deleteJson("/api/v1/campaigns/{$campana->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Campaign deleted successfully');

        $this->assertSoftDeleted('campaigns', ['id' => $campana->id]);
        $this->comoUsuario()->getJson("/api/v1/campaigns/{$campana->id}")->assertStatus(404);
    }

    public function test_no_se_puede_borrar_una_campanna_que_esta_enviando(): void
    {
        // El guardián de `destroy` comparaba con `in_progress`, un estado que no
        // existe en el enum y que nadie escribe nunca; el estado real mientras se
        // envía es `sending`, así que no protegía nada y se podía borrar una
        // campaña con el job a medio recorrer. Cerrado por la Spec 0038.
        $enviando = Campaign::factory()->forTenant($this->tenant)->status('sending')->create();

        $this->comoUsuario()->deleteJson("/api/v1/campaigns/{$enviando->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete campaign in progress');

        $this->assertDatabaseHas('campaigns', ['id' => $enviando->id, 'deleted_at' => null]);
    }

    // ==================================================================
    // send / cancel
    // ==================================================================

    public function test_send_acepta_solo_campannas_pendientes(): void
    {
        $pendiente = Campaign::factory()->forTenant($this->tenant)->create();

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$pendiente->id}/send")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Campaign queued for sending');

        foreach (['scheduled', 'sending', 'sent', 'failed', 'draft'] as $estado) {
            $otra = Campaign::factory()->forTenant($this->tenant)->status($estado)->create();

            $this->comoUsuario()->postJson("/api/v1/campaigns/{$otra->id}/send")
                ->assertStatus(422)
                ->assertJsonPath('message', 'Campaign is not in pending status');
        }
    }

    public function test_cancelar_una_campanna_la_deja_cancelada(): void
    {
        // Era el hallazgo: `cancel` escribía `status = 'cancelled'`, un valor que
        // el CHECK de la columna no admitía —ni en PostgreSQL ni fuera—, así que
        // el endpoint respondía **500** y no había forma de parar una campaña.
        // Cerrado por la Spec 0038; el detalle vive en `CampaignCancelTest`.
        $campana = Campaign::factory()->forTenant($this->tenant)->create();

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$campana->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Campaign cancelled')
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', $campana->fresh()->status);
    }

    public function test_el_guardian_de_cancelar_frena_una_campanna_ya_enviada(): void
    {
        // La guarda comparaba con `completed`, que tampoco está en el enum, así
        // que una campaña ya enviada la superaba y llegaba igualmente al 500. Ya
        // mira `sent`, el estado terminal real, y responde el 422 que tocaba.
        $enviada = Campaign::factory()->forTenant($this->tenant)->sent()->create();

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$enviada->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot cancel a campaign that was already sent');

        $this->assertSame('sent', $enviada->fresh()->status);
    }

    // ==================================================================
    // recipients
    // ==================================================================

    public function test_recipients_lista_los_destinatarios_paginados_de_cincuenta(): void
    {
        $campana = Campaign::factory()->forTenant($this->tenant)->create();
        $this->crearDestinatario($campana, 'ana@ejemplo.test');
        $this->crearDestinatario($campana, 'beatriz@ejemplo.test');

        $respuesta = $this->comoUsuario()->getJson("/api/v1/campaigns/{$campana->id}/recipients");

        $respuesta->assertStatus(200)->assertJsonStructure([
            'data' => [['id', 'campaign_id', 'recipient_type', 'recipient_value', 'status', 'error_message']],
            'meta' => ['total', 'current_page', 'last_page'],
        ]);

        $this->assertSame(2, $respuesta->json('meta.total'));

        // El `meta` de este endpoint no lleva `per_page`, a diferencia del index.
        $this->assertNull($respuesta->json('meta.per_page'));
    }

    public function test_recipients_de_una_campanna_ajena_da_404(): void
    {
        $ajena = Campaign::factory()->forTenant(Tenant::factory()->create())->create();
        $this->crearDestinatario($ajena, 'ana@ejemplo.test');

        $this->comoUsuario()->getJson("/api/v1/campaigns/{$ajena->id}/recipients")->assertStatus(404);
    }

    // ==================================================================
    // Permisos y aislamiento
    // ==================================================================

    public function test_cada_verbo_exige_su_permiso(): void
    {
        $campana = Campaign::factory()->forTenant($this->tenant)->create();
        [$pelado, $tokenPelado] = $this->createTenantWithUser([], $this->tenant);

        $sesion = fn () => $this->actingAsTenantUser($pelado, $tokenPelado);

        $sesion()->getJson('/api/v1/campaigns')->assertStatus(403);
        $sesion()->getJson("/api/v1/campaigns/{$campana->id}")->assertStatus(403);
        $sesion()->getJson("/api/v1/campaigns/{$campana->id}/recipients")->assertStatus(403);
        $sesion()->postJson('/api/v1/campaigns', $this->payload())->assertStatus(403);
        $sesion()->putJson("/api/v1/campaigns/{$campana->id}", ['title' => 'x'])->assertStatus(403);
        $sesion()->postJson("/api/v1/campaigns/{$campana->id}/send")->assertStatus(403);
        $sesion()->postJson("/api/v1/campaigns/{$campana->id}/cancel")->assertStatus(403);
        $sesion()->deleteJson("/api/v1/campaigns/{$campana->id}")->assertStatus(403);
    }

    public function test_sin_sesion_todo_el_modulo_responde_401(): void
    {
        $campana = Campaign::factory()->forTenant($this->tenant)->create();

        $this->getJson('/api/v1/campaigns')->assertStatus(401);
        $this->getJson("/api/v1/campaigns/{$campana->id}")->assertStatus(401);
        $this->postJson('/api/v1/campaigns', $this->payload())->assertStatus(401);
        $this->postJson("/api/v1/campaigns/{$campana->id}/send")->assertStatus(401);
    }

    public function test_una_campanna_de_otro_tenant_da_404_en_todas_sus_rutas(): void
    {
        $ajena = Campaign::factory()->forTenant(Tenant::factory()->create())->create();

        $this->comoUsuario()->getJson("/api/v1/campaigns/{$ajena->id}")->assertStatus(404);
        $this->comoUsuario()->putJson("/api/v1/campaigns/{$ajena->id}", ['title' => 'x'])->assertStatus(404);
        $this->comoUsuario()->postJson("/api/v1/campaigns/{$ajena->id}/send")->assertStatus(404);
        $this->comoUsuario()->postJson("/api/v1/campaigns/{$ajena->id}/cancel")->assertStatus(404);
        $this->comoUsuario()->deleteJson("/api/v1/campaigns/{$ajena->id}")->assertStatus(404);

        $this->assertSame('pending', $ajena->fresh()->status);
    }

    // ------------------------------------------------------------------

    private function comoUsuario(): static
    {
        return $this->actingAsTenantUser($this->user, $this->token);
    }

    private function crearDestinatario(Campaign $campana, string $valor, string $tipo = 'email'): CampaignRecipient
    {
        return CampaignRecipient::create([
            'campaign_id' => $campana->id,
            'recipient_type' => $tipo,
            'recipient_value' => $valor,
            'status' => 'pending',
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'title' => 'Invitación a la asamblea',
            'message' => 'Te esperamos el sábado en el salón comunal.',
            'channel' => 'whatsapp',
        ], $extra);
    }
}
