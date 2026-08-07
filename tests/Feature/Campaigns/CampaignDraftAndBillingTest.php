<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\Campaigns\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MessagingConfig;
use App\Models\MessagingCreditTransaction;
use App\Models\Tenant;
use App\Models\TenantMessagingCredit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ciclo borrador → envío y cobro de créditos (Spec 0040).
 *
 * Cierra los dos hallazgos de la caracterización 0013 que quedaban abiertos:
 *
 * 1. **Crear era enviar.** `store` resolvía los destinatarios y despachaba el
 *    job en el acto, así que no había ningún momento en el que revisar la
 *    campaña antes de que saliera.
 * 2. **El envío masivo no cobraba.** El flujo no tocaba `TenantMessagingCredit`
 *    en ningún punto: se enviaba con el saldo en cero y `whatsapp_used` nunca
 *    reflejaba lo enviado, que es justo lo que el tenant compra.
 *
 * Las dos reglas que fija esta spec:
 *
 * - **Crear = borrador.** El envío es una acción explícita, y es donde se
 *   resuelven los destinatarios: así lo que sale es el borrador tal y como quedó
 *   tras editarlo.
 * - **Un crédito por mensaje y canal, todo o nada.** Si a un canal le falta un
 *   solo crédito no sale ningún mensaje, no se descuenta nada y la campaña sigue
 *   en borrador.
 *
 * Las campañas de estas pruebas usan `custom_list` para que el número de
 * mensajes sea exactamente el que se escribe aquí y no dependa de cuántos
 * usuarios tenga el tenant de fondo.
 */
class CampaignDraftAndBillingTest extends TestCase
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

        // Precios del catálogo: la transacción de consumo los registra.
        MessagingConfig::setEmailPrice(50);
        MessagingConfig::setWhatsAppPrice(80);

        $this->tenant = Tenant::factory()->create();

        [$this->user, $this->token] = $this->createTenantWithUser(
            ['view_campaigns', 'create_campaigns', 'edit_campaigns'],
            $this->tenant
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ==================================================================
    // Crear = borrador
    // ==================================================================

    public function test_crear_una_campanna_la_deja_en_borrador_sin_enviar_ni_cobrar(): void
    {
        $this->conSaldo(100, 100);

        $respuesta = $this->crearBorrador(['3001112233']);

        $respuesta->assertStatus(201)
            ->assertJsonPath('message', 'Campaign saved as draft')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_recipients', 0);

        // Ni destinatarios resueltos, ni job, ni un crédito de menos.
        $this->assertSame(0, CampaignRecipient::count());
        Queue::assertNothingPushed();
        $this->assertSaldo(100, 100);
        $this->assertDatabaseCount('messaging_credit_transactions', 0);
    }

    public function test_crear_con_fecha_programada_tambien_queda_en_borrador(): void
    {
        $respuesta = $this->crearBorrador(['3001112233'], 'whatsapp', [
            'scheduled_at' => '2026-08-20 09:00:00',
        ]);

        $respuesta->assertStatus(201)->assertJsonPath('data.status', 'draft');

        $this->assertNotNull($respuesta->json('data.scheduled_at'), 'La fecha se guarda para cuando se envíe.');
        Queue::assertNothingPushed();
    }

    public function test_el_borrador_se_puede_editar_y_lo_editado_es_lo_que_sale(): void
    {
        // El motivo de resolver los destinatarios al enviar y no al crear.
        $this->conSaldo(100, 100);

        $id = $this->crearBorrador(['3001112233'])->json('data.id');

        $this->comoUsuario()->putJson("/api/v1/campaigns/{$id}", [
            'filter_json' => $this->listaPersonalizada(['3004445566', '3007778899']),
        ])->assertStatus(200);

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")
            ->assertStatus(200)
            ->assertJsonPath('data.total_recipients', 2);

        // Salen los dos nuevos, no el del borrador original.
        $this->assertEqualsCanonicalizing(
            ['3004445566', '3007778899'],
            CampaignRecipient::pluck('recipient_value')->all()
        );

        $this->assertSaldo(100, 98);
    }

    public function test_una_campanna_que_ya_no_es_borrador_no_se_edita(): void
    {
        $this->conSaldo(100, 100);

        $id = $this->crearBorrador(['3001112233'])->json('data.id');
        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(200);

        $this->comoUsuario()->putJson("/api/v1/campaigns/{$id}", ['title' => 'Tarde'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot update campaign that is not a draft');
    }

    // ==================================================================
    // Enviar: saldo insuficiente
    // ==================================================================

    public function test_sin_saldo_suficiente_no_sale_nada_y_se_dice_que_falta(): void
    {
        // Tres mensajes de WhatsApp y saldo para uno.
        $this->conSaldo(0, 1);

        $id = $this->crearBorrador(['3001112233', '3004445566', '3007778899'])->json('data.id');

        $respuesta = $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send");

        $respuesta->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient messaging credits to send this campaign')
            ->assertJsonPath('credits.whatsapp.needed', 3)
            ->assertJsonPath('credits.whatsapp.available', 1)
            ->assertJsonPath('credits.whatsapp.missing', 2)
            // El canal que no se usa viaja igual, en cero: forma estable.
            ->assertJsonPath('credits.email.needed', 0)
            ->assertJsonPath('credits.email.missing', 0);

        // Saldo intacto, sin transacción, sin job, y sigue en borrador.
        $this->assertSaldo(0, 1);
        $this->assertDatabaseCount('messaging_credit_transactions', 0);
        Queue::assertNothingPushed();

        $campana = Campaign::findOrFail($id);

        $this->assertSame('draft', $campana->status);
        $this->assertNull($campana->queued_at);
        // Tampoco quedan destinatarios a medio resolver.
        $this->assertSame(0, CampaignRecipient::count());
    }

    public function test_en_canal_doble_falta_uno_y_no_sale_ninguno(): void
    {
        // Todo o nada de verdad: el correo alcanza, WhatsApp no, y no sale nada
        // por ninguno de los dos.
        $this->conSaldo(10, 0);

        $id = $this->crearBorrador(['3001112233'], 'both', [
            'filter_json' => $this->listaPersonalizada(['3001112233'], ['ana@ejemplo.test']),
        ])->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")
            ->assertStatus(422)
            ->assertJsonPath('credits.email.needed', 1)
            ->assertJsonPath('credits.email.missing', 0)
            ->assertJsonPath('credits.whatsapp.needed', 1)
            ->assertJsonPath('credits.whatsapp.missing', 1);

        $this->assertSaldo(10, 0);
        $this->assertDatabaseCount('messaging_credit_transactions', 0);
        Queue::assertNothingPushed();
    }

    public function test_un_tenant_sin_fila_de_creditos_no_envia_gratis(): void
    {
        // Antes era el agujero: sin fila de créditos el mensaje salía igual y no
        // se cobraba. Ahora el saldo es una autorización previa.
        $id = $this->crearBorrador(['3001112233'])->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")
            ->assertStatus(422)
            ->assertJsonPath('credits.whatsapp.available', 0)
            ->assertJsonPath('credits.whatsapp.missing', 1);

        Queue::assertNothingPushed();
        $this->assertSame('draft', Campaign::findOrFail($id)->status);
    }

    public function test_tras_recargar_el_saldo_el_mismo_borrador_ya_sale(): void
    {
        $id = $this->crearBorrador(['3001112233'])->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(422);

        $this->conSaldo(0, 5);

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(200);

        Queue::assertPushed(SendCampaignJob::class, 1);
        $this->assertSaldo(0, 4);
    }

    public function test_una_campanna_sin_destinatarios_no_cobra_ni_despacha(): void
    {
        $this->conSaldo(10, 10);

        // Canal WhatsApp y una lista con solo correos: no queda nadie a quien
        // escribir.
        $id = $this->crearBorrador([], 'whatsapp', [
            'filter_json' => $this->listaPersonalizada([], ['ana@ejemplo.test']),
        ])->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Campaign has no recipients to send');

        $this->assertSaldo(10, 10);
        Queue::assertNothingPushed();
        $this->assertSame('draft', Campaign::findOrFail($id)->status);
    }

    // ==================================================================
    // Enviar: saldo suficiente
    // ==================================================================

    public function test_con_saldo_descuenta_por_canal_registra_y_despacha_una_vez(): void
    {
        $this->conSaldo(10, 10);

        $id = $this->crearBorrador(['3001112233', '3004445566'])->json('data.id');

        $respuesta = $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send");

        $respuesta->assertStatus(200)
            ->assertJsonPath('message', 'Campaign queued for sending')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total_recipients', 2);

        // Dos mensajes de WhatsApp: dos créditos de ese canal y ninguno de correo.
        $this->assertSaldo(10, 8);

        $credito = $this->credito();

        $this->assertSame(2, $credito->whatsapp_used);
        $this->assertSame(0, $credito->emails_used);

        Queue::assertPushed(SendCampaignJob::class, 1);

        $campana = Campaign::findOrFail($id);

        $this->assertNotNull($campana->queued_at);
        $this->assertSame(2, CampaignRecipient::count());
    }

    public function test_el_consumo_queda_registrado_como_transaccion(): void
    {
        $this->conSaldo(10, 10);

        $id = $this->crearBorrador(['3001112233'])->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(200);

        $transaccion = MessagingCreditTransaction::where('type', 'whatsapp')->firstOrFail();

        $this->assertSame($this->tenant->id, $transaccion->tenant_id);
        $this->assertSame('consumption', $transaccion->transaction_type);
        $this->assertSame(-1, $transaccion->quantity);
        $this->assertSame('80.00', $transaccion->unit_price);
        $this->assertSame('completed', $transaccion->status);
        $this->assertStringContainsString("Campaign #{$id}", $transaccion->reference);
    }

    public function test_el_canal_doble_descuenta_de_los_dos_saldos(): void
    {
        $this->conSaldo(10, 10);

        $id = $this->crearBorrador([], 'both', [
            'filter_json' => $this->listaPersonalizada(
                ['3001112233', '3004445566'],
                ['ana@ejemplo.test', 'beatriz@ejemplo.test']
            ),
        ])->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")
            ->assertStatus(200)
            ->assertJsonPath('data.total_recipients', 4);

        // Dos correos y dos WhatsApp: dos créditos de cada saldo.
        $this->assertSaldo(8, 8);

        $this->assertSame(1, MessagingCreditTransaction::where('type', 'email')->count());
        $this->assertSame(1, MessagingCreditTransaction::where('type', 'whatsapp')->count());
    }

    public function test_enviar_dos_veces_no_cobra_dos_veces(): void
    {
        $this->conSaldo(10, 10);

        $id = $this->crearBorrador(['3001112233'])->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(200);

        // Ya no es borrador: el segundo intento ni resuelve ni cobra (Spec 0038).
        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Campaign is not a draft');

        $this->assertSaldo(10, 9);
        Queue::assertPushed(SendCampaignJob::class, 1);
        $this->assertSame(1, MessagingCreditTransaction::count());
    }

    // ==================================================================
    // Aislamiento
    // ==================================================================

    public function test_el_cobro_sale_del_saldo_del_tenant_de_la_campanna(): void
    {
        $this->conSaldo(10, 10);

        $otro = Tenant::factory()->create();
        $creditoAjeno = TenantMessagingCredit::create([
            'tenant_id' => $otro->id,
            'emails_available' => 10,
            'emails_used' => 0,
            'whatsapp_available' => 10,
            'whatsapp_used' => 0,
        ]);

        $id = $this->crearBorrador(['3001112233'])->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(200);

        $this->assertSaldo(10, 9);
        $this->assertSame(10, $creditoAjeno->fresh()->whatsapp_available, 'El saldo ajeno no se toca.');
    }

    public function test_enviar_la_campanna_de_otro_tenant_da_404_y_no_cobra(): void
    {
        $this->conSaldo(10, 10);

        $ajena = Campaign::factory()->forTenant(Tenant::factory()->create())->status('draft')->create();

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$ajena->id}/send")->assertStatus(404);

        $this->assertSaldo(10, 10);
        Queue::assertNothingPushed();
    }

    public function test_enviar_exige_permiso_de_edicion(): void
    {
        [$pelado, $tokenPelado] = $this->createTenantWithUser([], $this->tenant);

        $id = $this->crearBorrador(['3001112233'])->json('data.id');

        $this->actingAsTenantUser($pelado, $tokenPelado)
            ->postJson("/api/v1/campaigns/{$id}/send")
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------------

    private function comoUsuario(): static
    {
        return $this->actingAsTenantUser($this->user, $this->token);
    }

    /**
     * @param  array<int, string>  $telefonos
     * @param  array<string, mixed>  $extra
     */
    private function crearBorrador(array $telefonos, string $canal = 'whatsapp', array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->comoUsuario()->postJson('/api/v1/campaigns', array_merge([
            'title' => 'Invitación a la asamblea',
            'message' => 'Te esperamos el sábado en el salón comunal.',
            'channel' => $canal,
            'filter_json' => $this->listaPersonalizada($telefonos),
        ], $extra));
    }

    /**
     * @param  array<int, string>  $telefonos
     * @param  array<int, string>  $correos
     * @return array<string, mixed>
     */
    private function listaPersonalizada(array $telefonos, array $correos = []): array
    {
        $destinatarios = array_map(
            fn (string $telefono) => ['type' => 'phone', 'value' => $telefono],
            $telefonos
        );

        foreach ($correos as $correo) {
            $destinatarios[] = ['type' => 'email', 'value' => $correo];
        }

        return [
            'target' => 'custom_list',
            'custom_recipients' => $destinatarios,
        ];
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

    private function credito(): TenantMessagingCredit
    {
        return TenantMessagingCredit::where('tenant_id', $this->tenant->id)->firstOrFail();
    }

    private function assertSaldo(int $correos, int $whatsapp): void
    {
        $credito = $this->credito();

        $this->assertSame($correos, $credito->emails_available, 'Saldo de correo.');
        $this->assertSame($whatsapp, $credito->whatsapp_available, 'Saldo de WhatsApp.');
    }
}
