<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\Campaigns\SendCampaignJob;
use App\Models\Barrio;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Commune;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MessagingCreditTransaction;
use App\Models\Municipality;
use App\Models\Tenant;
use App\Models\TenantMessagingCredit;
use App\Models\TenantWhatsAppInstance;
use App\Models\User;
use App\Services\CampaignService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN del envío asíncrono de campañas (Spec 0013, Fase 2).
 *
 * Tres piezas encadenadas:
 *
 * 1. `CampaignService::createCampaign` — resuelve los destinatarios **al crear**
 *    y despacha `SendCampaignJob` (inmediato o con `delay` si está programada).
 * 2. `generateRecipients` — cuatro objetivos: `all_users`, `meeting_attendees`,
 *    `custom_list` y `by_location`, con deduplicación por tipo+valor.
 * 3. `SendCampaignJob::handle` — marca `sending`, recorre los destinatarios
 *    `pending` por trozos con `sleep(1)` entre ellos, y termina en `sent`.
 *
 * Tiempo fijo y sin red: `Queue::fake` para el encolado, `Http::fake` +
 * `preventStrayRequests` para WhatsApp (Evolution) y correo (webhook de n8n).
 */
class CampaignSendCharacterizationTest extends TestCase
{
    private Tenant $tenant;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 12:00:00');

        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();

        [$this->user, $this->token] = $this->createTenantWithUser(
            ['view_campaigns', 'create_campaigns', 'edit_campaigns'],
            $this->tenant
        );

        $this->conSaldoDeSobra();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ==================================================================
    // Qué se encola
    // ==================================================================

    public function test_crear_una_campanna_ya_no_encola_nada(): void
    {
        // Era el hallazgo: el alta resolvía los destinatarios y despachaba el
        // envío en el acto, así que crear era enviar. Desde la Spec 0040 el alta
        // deja un borrador y enviar es una acción aparte; el detalle vive en
        // `CampaignDraftAndBillingTest`.
        Queue::fake();

        $this->crearPorApi()->assertStatus(201)->assertJsonPath('data.status', 'draft');

        Queue::assertNothingPushed();
        $this->assertSame(0, CampaignRecipient::count());
    }

    public function test_enviar_encola_el_job_sin_delay(): void
    {
        Queue::fake();

        User::factory()->forTenant($this->tenant)->create(['phone' => '3001112233']);

        $this->crearYEnviar()->assertStatus(200);

        Queue::assertPushed(SendCampaignJob::class, 1);
        Queue::assertPushed(SendCampaignJob::class, function (SendCampaignJob $job) {
            $this->assertNull($job->delay, 'Sin fecha programada el envío sale ya.');

            return true;
        });
    }

    public function test_una_campanna_programada_encola_el_job_con_delay(): void
    {
        Queue::fake();

        User::factory()->forTenant($this->tenant)->create(['phone' => '3001112233']);

        $this->crearYEnviar(['scheduled_at' => '2026-08-20 09:00:00'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'scheduled');

        Queue::assertPushed(SendCampaignJob::class, function (SendCampaignJob $job) {
            $this->assertSame('2026-08-20 09:00:00', $job->delay->toDateTimeString());
            $this->assertSame('America/Bogota', $job->delay->timezoneName);

            return true;
        });
    }

    public function test_send_no_encola_un_segundo_envio_de_la_misma_campanna(): void
    {
        // Era el hallazgo: el alta encolaba el envío y dejaba la campaña en
        // `pending`, que es justo el estado que `send` exigía, así que pulsar
        // «enviar» encolaba un SEGUNDO job y —si el primero no había corrido—
        // los dos veían destinatarios `pending` y la gente recibía el mensaje dos
        // veces. Cerrado por la Spec 0038 con `queued_at` y rematado por la 0040,
        // que hace del envío una acción única sobre un borrador.
        Queue::fake();

        User::factory()->forTenant($this->tenant)->create(['phone' => '3001112233']);

        $id = $this->crearPorApi()->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(200);

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Campaign is not a draft');

        Queue::assertPushed(SendCampaignJob::class, 1);
    }

    // ==================================================================
    // Generación de destinatarios
    // ==================================================================

    public function test_por_defecto_el_objetivo_son_todos_los_usuarios_del_tenant(): void
    {
        Queue::fake();

        User::factory()->forTenant($this->tenant)->create(['phone' => '3001112233']);
        User::factory()->forTenant($this->tenant)->create(['phone' => null]);
        // De otra campaña: no debe entrar (Art. III).
        User::factory()->forTenant(Tenant::factory()->create())->create(['phone' => '3009998877']);

        $this->crearYEnviar(['channel' => 'whatsapp']);

        // Solo el usuario del tenant con teléfono. El de la sesión no tiene.
        $this->assertSame(['3001112233'], CampaignRecipient::pluck('recipient_value')->all());
    }

    public function test_el_canal_decide_que_dato_se_toma_de_cada_persona(): void
    {
        Queue::fake();

        $usuario = User::factory()->forTenant($this->tenant)->create([
            'email' => 'ana@ejemplo.test',
            'phone' => '3001112233',
        ]);

        $this->crearYEnviar(['channel' => 'both']);

        $suyos = CampaignRecipient::where('recipient_name', $usuario->name)->get();

        $this->assertEqualsCanonicalizing(
            ['email', 'whatsapp'],
            $suyos->pluck('recipient_type')->all(),
            'Con canal `both` cada persona genera dos destinatarios.'
        );
    }

    public function test_el_objetivo_de_asistentes_toma_los_de_las_reuniones_pedidas(): void
    {
        Queue::fake();

        $reunion = Meeting::factory()->forTenant($this->tenant)->create();
        $otraReunion = Meeting::factory()->forTenant($this->tenant)->create();

        $this->crearAsistente($reunion, '3001112233');
        $this->crearAsistente($otraReunion, '3004445566');

        $this->crearYEnviar([
            'channel' => 'whatsapp',
            'filter_json' => ['target' => 'meeting_attendees', 'meeting_ids' => [$reunion->id]],
        ]);

        $this->assertSame(['3001112233'], CampaignRecipient::pluck('recipient_value')->all());
    }

    public function test_pedir_la_reunion_de_otra_campanna_no_trae_a_sus_asistentes(): void
    {
        // `meeting_ids` se valida con `exists:meetings,id`, sin filtro de tenant,
        // así que el id ajeno pasa la validación. Quien protege es el
        // `TenantScope` de `MeetingAttendee`: la consulta no ve esas filas.
        Queue::fake();

        $otro = Tenant::factory()->create();
        $reunionAjena = Meeting::factory()->forTenant($otro)->create();
        $this->crearAsistente($reunionAjena, '3009998877', $otro);

        $respuesta = $this->crearYEnviar([
            'channel' => 'whatsapp',
            'filter_json' => ['target' => 'meeting_attendees', 'meeting_ids' => [$reunionAjena->id]],
        ]);

        // No resuelve a nadie, así que ni se cobra ni se despacha (Spec 0040).
        $respuesta->assertStatus(422)->assertJsonPath('message', 'Campaign has no recipients to send');
        $this->assertSame(0, CampaignRecipient::count());
        Queue::assertNothingPushed();
    }

    public function test_hallazgo_los_destinatarios_de_una_reunion_van_sin_nombre(): void
    {
        // `extractRecipientsFromAttendees` lee `$attendee->nombre`, pero el
        // modelo tiene `nombres` y `apellidos` (y un accesor `full_name`). El
        // nombre se guarda siempre en null, así que el listado de destinatarios
        // no dice a quién se escribió.
        Queue::fake();

        $reunion = Meeting::factory()->forTenant($this->tenant)->create();
        $this->crearAsistente($reunion, '3001112233');

        $this->crearYEnviar([
            'channel' => 'whatsapp',
            'filter_json' => ['target' => 'meeting_attendees', 'meeting_ids' => [$reunion->id]],
        ]);

        $this->assertNull(CampaignRecipient::firstOrFail()->recipient_name);
    }

    public function test_la_lista_personalizada_respeta_el_canal(): void
    {
        Queue::fake();

        $this->crearYEnviar([
            'channel' => 'whatsapp',
            'filter_json' => [
                'target' => 'custom_list',
                'custom_recipients' => [
                    ['type' => 'phone', 'value' => '3001112233', 'name' => 'Ana'],
                    // El correo se descarta: el canal es solo WhatsApp.
                    ['type' => 'email', 'value' => 'ana@ejemplo.test'],
                ],
            ],
        ]);

        $destinatario = CampaignRecipient::firstOrFail();

        $this->assertSame(1, CampaignRecipient::count());
        $this->assertSame('whatsapp', $destinatario->recipient_type);
        $this->assertSame('3001112233', $destinatario->recipient_value);
    }

    public function test_hallazgo_el_nombre_de_la_lista_personalizada_se_pierde_en_la_validacion(): void
    {
        // `extractCustomRecipients` sí lee `name`, pero `StoreCampaignRequest` no
        // declara ninguna regla para `filter_json.custom_recipients.*.name`, y
        // `validated()` devuelve **solo** las claves validadas: el nombre se cae
        // antes de llegar al servicio. Sumado a los asistentes —que tampoco
        // guardan nombre—, la lista de destinatarios casi nunca dice a quién se
        // escribió.
        Queue::fake();

        $this->crearYEnviar([
            'channel' => 'whatsapp',
            'filter_json' => [
                'target' => 'custom_list',
                'custom_recipients' => [
                    ['type' => 'phone', 'value' => '3001112233', 'name' => 'Ana'],
                ],
            ],
        ])->assertStatus(200);

        $this->assertNull(CampaignRecipient::firstOrFail()->recipient_name);
    }

    public function test_el_objetivo_por_ubicacion_filtra_por_el_nivel_mas_especifico(): void
    {
        Queue::fake();

        [$barrio, $otroBarrio] = $this->geografia();

        $reunion = Meeting::factory()->forTenant($this->tenant)->create();
        $this->crearAsistente($reunion, '3001112233', null, $barrio->id);
        $this->crearAsistente($reunion, '3004445566', null, $otroBarrio->id);

        $this->crearYEnviar([
            'channel' => 'whatsapp',
            'filter_json' => ['target' => 'by_location', 'barrio_id' => $barrio->id],
        ]);

        $this->assertSame(['3001112233'], CampaignRecipient::pluck('recipient_value')->all());
    }

    public function test_el_objetivo_por_ubicacion_sube_a_comuna_municipio_y_departamento(): void
    {
        Queue::fake();

        [$barrio, $otroBarrio] = $this->geografia();

        $reunion = Meeting::factory()->forTenant($this->tenant)->create();
        $this->crearAsistente($reunion, '3001112233', null, $barrio->id);
        $this->crearAsistente($reunion, '3004445566', null, $otroBarrio->id);

        // Comuna: solo el primero. Municipio y departamento: los dos, porque el
        // otro barrio cuelga del mismo municipio sin comuna.
        $departamentoId = Municipality::findOrFail($barrio->municipality_id)->department_id;

        $esperado = [
            [['commune_id' => $barrio->commune_id], 1],
            [['municipality_id' => $barrio->municipality_id], 2],
            [['department_id' => $departamentoId], 2],
        ];

        foreach ($esperado as [$filtro, $cuantos]) {
            CampaignRecipient::query()->delete();

            $this->crearYEnviar([
                'channel' => 'whatsapp',
                'filter_json' => array_merge(['target' => 'by_location'], $filtro),
            ])->assertStatus(200);

            $this->assertSame($cuantos, CampaignRecipient::count(), 'Filtro: '.json_encode($filtro));
        }
    }

    public function test_los_destinatarios_repetidos_se_deduplican(): void
    {
        Queue::fake();

        $this->crearYEnviar([
            'channel' => 'whatsapp',
            'filter_json' => [
                'target' => 'custom_list',
                'custom_recipients' => [
                    ['type' => 'phone', 'value' => '3001112233'],
                    ['type' => 'phone', 'value' => '3001112233'],
                ],
            ],
        ])->assertJsonPath('data.total_recipients', 1);

        $this->assertSame(1, CampaignRecipient::count());
    }

    public function test_una_campanna_sin_destinatarios_se_guarda_pero_no_se_envia(): void
    {
        // Antes se creaba **y se encolaba** un envío sin nadie a quien escribir.
        // Ahora el borrador se guarda y enviar avisa de que no hay destinatarios
        // (Spec 0040).
        Queue::fake();

        $this->crearPorApi([
            'channel' => 'whatsapp',
            'filter_json' => ['target' => 'custom_list', 'custom_recipients' => []],
        ])->assertStatus(201)->assertJsonPath('data.total_recipients', 0);

        Queue::assertNothingPushed();

        $this->crearYEnviar([
            'channel' => 'whatsapp',
            'filter_json' => ['target' => 'custom_list', 'custom_recipients' => []],
        ])->assertStatus(422)->assertJsonPath('message', 'Campaign has no recipients to send');

        Queue::assertNothingPushed();
    }

    // ==================================================================
    // El job en ejecución
    // ==================================================================

    public function test_el_job_envia_a_cada_destinatario_y_cierra_la_campanna(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->instanciaWhatsApp();
        $campana = $this->campannaConDestinatarios(['3001112233', '3004445566']);

        $this->ejecutar($campana);

        $campana->refresh();

        $this->assertSame('sent', $campana->status);
        $this->assertSame(2, $campana->sent_count);
        $this->assertSame(0, $campana->failed_count);
        $this->assertNotNull($campana->sent_at);

        $this->assertSame(['sent', 'sent'], CampaignRecipient::pluck('status')->all());
        $this->assertNotNull(CampaignRecipient::firstOrFail()->sent_at);

        Http::assertSentCount(2);
    }

    public function test_el_job_espera_un_segundo_por_cada_trozo(): void
    {
        // Rate limiting: un `sleep(1)` por trozo de `config('campaign.batch_size')`.
        // Con el tamaño por defecto (100) y dos destinatarios hay un solo trozo,
        // así que el job no puede tardar menos de un segundo.
        Http::fake(['*' => Http::response([], 200)]);

        $this->instanciaWhatsApp();
        $campana = $this->campannaConDestinatarios(['3001112233', '3004445566']);

        $inicio = microtime(true);
        $this->ejecutar($campana);

        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $inicio);
        $this->assertSame(2, $campana->refresh()->sent_count);
    }

    public function test_a_partir_del_segundo_trozo_ya_no_se_saltan_destinatarios(): void
    {
        // Era el hallazgo 🔴 de esta caracterización:
        // `recipients()->where('status','pending')->chunk($n, ...)` paginaba con
        // OFFSET sobre una consulta cuyo resultado **cambia mientras se recorre**
        // —cada envío saca al destinatario de `pending`—, así que el OFFSET de la
        // página siguiente se comía las filas que faltaban. Con tres
        // destinatarios y trozos de uno salían **dos**, el tercero se quedaba en
        // `pending` para siempre y la campaña se cerraba como `sent` igual.
        //
        // Cerrado por la Spec 0037 con `chunkById`. El detalle vive en
        // `CampaignSendCompletenessTest`.
        Http::fake(['*' => Http::response([], 200)]);
        config(['campaign.batch_size' => 1]);

        $this->instanciaWhatsApp();
        $campana = $this->campannaConDestinatarios(['3001112233', '3004445566', '3007778899']);

        $this->ejecutar($campana);

        Http::assertSentCount(3);

        $campana->refresh();

        $this->assertSame(3, $campana->sent_count);
        $this->assertSame(0, $campana->failed_count);
        $this->assertSame('sent', $campana->status);

        $this->assertSame(0, CampaignRecipient::where('status', 'pending')->count());
    }

    public function test_un_envio_fallido_deja_el_destinatario_en_failed_con_su_motivo(): void
    {
        // Sin instancia de WhatsApp el servicio devuelve false y el service lo
        // convierte en excepción, que se registra en el destinatario.
        Http::fake();

        $campana = $this->campannaConDestinatarios(['3001112233']);

        $this->ejecutar($campana);

        $destinatario = CampaignRecipient::firstOrFail();

        $this->assertSame('failed', $destinatario->status);
        $this->assertSame('WhatsApp service returned false', $destinatario->error_message);

        $campana->refresh();

        $this->assertSame(1, $campana->failed_count);
        $this->assertSame(0, $campana->sent_count);
        // Aun con todo fallido, la campaña termina en `sent`.
        $this->assertSame('sent', $campana->status);
    }

    public function test_el_correo_va_al_webhook_con_el_token_guardado_en_la_campanna(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $campana = $this->campannaConDestinatarios(['ana@ejemplo.test'], 'email');
        $campana->update(['channel' => 'email', 'creator_token' => 'token-de-un-anno']);

        $this->ejecutar($campana);

        Http::assertSent(function ($request) use ($campana) {
            $this->assertSame('Bearer token-de-un-anno', $request->header('Authorization')[0]);
            $this->assertSame('ana@ejemplo.test', $request['email']);
            $this->assertSame($campana->message, $request['message']);

            return true;
        });

        $this->assertSame('sent', CampaignRecipient::firstOrFail()->status);
    }

    public function test_sin_token_guardado_el_correo_falla_con_su_mensaje(): void
    {
        Http::fake();

        $campana = $this->campannaConDestinatarios(['ana@ejemplo.test'], 'email');
        $campana->update(['channel' => 'email', 'creator_token' => null]);

        $this->ejecutar($campana);

        $this->assertSame(
            'No authentication token available',
            CampaignRecipient::firstOrFail()->error_message
        );
    }

    public function test_el_job_ignora_las_campannas_que_no_estan_pendientes_ni_programadas(): void
    {
        Http::fake();

        $campana = $this->campannaConDestinatarios(['3001112233']);
        $campana->update(['status' => 'sent']);

        $this->ejecutar($campana);

        Http::assertNothingSent();
        $this->assertSame('pending', CampaignRecipient::firstOrFail()->status);
    }

    public function test_si_el_job_falla_la_campanna_queda_en_failed(): void
    {
        $campana = $this->campannaConDestinatarios(['3001112233']);

        (new SendCampaignJob($campana))->failed(new \RuntimeException('se cayó la cola'));

        $this->assertSame('failed', $campana->refresh()->status);
    }

    // ==================================================================
    // Créditos de mensajería
    // ==================================================================

    public function test_las_campannas_consumen_creditos_de_mensajeria(): void
    {
        // Era el hallazgo 🔴: el envío de campañas **no tocaba
        // `TenantMessagingCredit` en ningún punto** —ni comprobaba saldo antes ni
        // descontaba después—, así que el envío masivo, que es el que consume de
        // verdad, salía gratis y no quedaba registrado en el contador que el
        // tenant compra. Cerrado por la Spec 0040, que cobra **al enviar**; el
        // detalle vive en `CampaignDraftAndBillingTest`.
        Queue::fake();

        $this->conSaldo(0, 10);

        $this->crearYEnviar([
            'channel' => 'whatsapp',
            'filter_json' => [
                'target' => 'custom_list',
                'custom_recipients' => [
                    ['type' => 'phone', 'value' => '3001112233'],
                    ['type' => 'phone', 'value' => '3004445566'],
                ],
            ],
        ])->assertStatus(200);

        $credito = TenantMessagingCredit::where('tenant_id', $this->tenant->id)->firstOrFail();

        $this->assertSame(8, $credito->whatsapp_available);
        $this->assertSame(2, $credito->whatsapp_used);
        $this->assertSame(1, MessagingCreditTransaction::where('type', 'whatsapp')->count());
    }

    public function test_una_campanna_no_se_envia_si_el_tenant_no_tiene_saldo(): void
    {
        Queue::fake();

        $this->conSaldo(0, 0);

        $this->crearYEnviar([
            'channel' => 'whatsapp',
            'filter_json' => [
                'target' => 'custom_list',
                'custom_recipients' => [['type' => 'phone', 'value' => '3001112233']],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('credits.whatsapp.missing', 1);

        Queue::assertNothingPushed();
        $this->assertSame(0, CampaignRecipient::count());
    }

    public function test_el_job_no_vuelve_a_cobrar_lo_que_ya_se_cobro_al_enviar(): void
    {
        // El cobro es del despacho, no del job: cuando el job corre, el crédito
        // ya está descontado y no se toca otra vez.
        Http::fake(['*' => Http::response([], 200)]);

        $this->instanciaWhatsApp();
        $credito = $this->conSaldo(0, 10);

        $campana = $this->campannaConDestinatarios(['3001112233', '3004445566']);

        $this->ejecutar($campana);

        Http::assertSentCount(2);

        $this->assertSame(10, $credito->fresh()->whatsapp_available);
        $this->assertDatabaseCount('messaging_credit_transactions', 0);
    }

    // ------------------------------------------------------------------

    private function comoUsuario(): static
    {
        return $this->actingAsTenantUser($this->user, $this->token);
    }

    private function ejecutar(Campaign $campana): void
    {
        (new SendCampaignJob($campana))->handle(app(CampaignService::class));
    }

    /**
     * Alta por API: desde la Spec 0040 deja un **borrador**.
     *
     * @param  array<string, mixed>  $extra
     */
    private function crearPorApi(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->comoUsuario()->postJson('/api/v1/campaigns', array_merge([
            'title' => 'Invitación a la asamblea',
            'message' => 'Te esperamos el sábado en el salón comunal.',
            'channel' => 'whatsapp',
        ], $extra));
    }

    /**
     * Crear el borrador y enviarlo, que es donde se resuelven los destinatarios
     * (Spec 0040). Devuelve la respuesta del **envío**.
     *
     * @param  array<string, mixed>  $extra
     */
    private function crearYEnviar(array $extra = []): \Illuminate\Testing\TestResponse
    {
        $id = $this->crearPorApi($extra)->json('data.id');

        return $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send");
    }

    /**
     * Saldo de sobra: en este archivo se caracteriza la resolución de
     * destinatarios y el envío, no el cobro (eso es `CampaignDraftAndBillingTest`).
     */
    private function conSaldoDeSobra(): void
    {
        $this->conSaldo(1000, 1000);
    }

    private function conSaldo(int $correos, int $whatsapp): TenantMessagingCredit
    {
        return TenantMessagingCredit::updateOrCreate(
            ['tenant_id' => $this->tenant->id],
            [
                'emails_available' => $correos,
                'emails_used' => 0,
                'whatsapp_available' => $whatsapp,
                'whatsapp_used' => 0,
            ]
        );
    }

    /**
     * Campaña `pending` con sus destinatarios ya resueltos, sin pasar por la API.
     *
     * @param  array<int, string>  $valores
     */
    private function campannaConDestinatarios(array $valores, string $tipo = 'whatsapp'): Campaign
    {
        $campana = Campaign::factory()->forTenant($this->tenant)->create([
            'created_by' => $this->user->id,
            'channel' => $tipo === 'email' ? 'email' : 'whatsapp',
            'total_recipients' => count($valores),
        ]);

        foreach ($valores as $valor) {
            CampaignRecipient::create([
                'campaign_id' => $campana->id,
                'recipient_type' => $tipo,
                'recipient_value' => $valor,
                'status' => 'pending',
            ]);
        }

        return $campana;
    }

    private function crearAsistente(Meeting $reunion, string $telefono, ?Tenant $tenant = null, ?int $barrioId = null): void
    {
        $reunion->attendees()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'cedula' => fake()->unique()->numerify('#########'),
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
            'telefono' => $telefono,
            'barrio_id' => $barrioId,
        ]);
    }

    /**
     * Departamento → municipio → (comuna → barrio) y un barrio colgado del
     * municipio sin comuna, para probar los cuatro niveles del filtro.
     *
     * @return array{0: Barrio, 1: Barrio}
     */
    private function geografia(): array
    {
        $departamento = Department::create(['codigo' => '73', 'nombre' => 'Tolima']);
        $municipio = Municipality::create([
            'department_id' => $departamento->id,
            'codigo' => '73001',
            'nombre' => 'Ibagué',
        ]);
        $comuna = Commune::create([
            'municipality_id' => $municipio->id,
            'codigo' => 'C1',
            'nombre' => 'Comuna 1',
        ]);

        return [
            Barrio::create([
                'municipality_id' => $municipio->id,
                'commune_id' => $comuna->id,
                'codigo' => 'B1',
                'nombre' => 'Centenario',
            ]),
            Barrio::create([
                'municipality_id' => $municipio->id,
                'commune_id' => null,
                'codigo' => 'B2',
                'nombre' => 'La Pola',
            ]),
        ];
    }

    private function instanciaWhatsApp(): TenantWhatsAppInstance
    {
        return TenantWhatsAppInstance::create([
            'tenant_id' => $this->tenant->id,
            'phone_number' => '573000000000',
            'instance_name' => 'instancia-1',
            'evolution_api_key' => 'clave-de-prueba',
            'evolution_api_url' => 'https://evolution.test',
            'daily_message_limit' => 1000,
            'messages_sent_today' => 0,
            'last_reset_date' => Carbon::today(),
            'is_active' => true,
        ]);
    }
}
