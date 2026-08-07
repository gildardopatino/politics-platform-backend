<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\Campaigns\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MessagingCreditTransaction;
use App\Models\Tenant;
use App\Models\TenantMessagingCredit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Campañas programadas y bordes del cobro (Spec 0040, Fase 3).
 *
 * Una campaña programada se **cobra al enviarla**, no cuando llega su hora: el
 * saldo se reserva en el momento en que alguien decide que salga, y el job solo
 * la entrega. Así el envío no puede fallar por falta de saldo a las tres de la
 * mañana, y lo cobrado ya no está disponible para otra campaña.
 */
class CampaignScheduledSendTest extends TestCase
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

    public function test_una_programada_se_cobra_al_enviarla_y_se_despacha_con_delay(): void
    {
        $this->conSaldo(0, 5);

        $id = $this->crearBorrador(['3001112233', '3004445566'], [
            'scheduled_at' => '2026-08-20 09:00:00',
        ])->json('data.id');

        // Todavía es un borrador: nada cobrado, nada encolado.
        $this->assertSaldo(0, 5);
        Queue::assertNothingPushed();

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.total_recipients', 2);

        // El cobro es de ahora, aunque el envío sea del día 20.
        $this->assertSaldo(0, 3);
        $this->assertSame(1, MessagingCreditTransaction::count());

        Queue::assertPushed(SendCampaignJob::class, function (SendCampaignJob $job) {
            $this->assertSame('2026-08-20 09:00:00', $job->delay->toDateTimeString());

            return true;
        });
    }

    public function test_si_la_fecha_programada_ya_paso_el_envio_sale_de_inmediato(): void
    {
        // El borrador se guardó con fecha futura y se envía cuando esa fecha ya
        // quedó atrás: no tiene sentido programarlo para el pasado.
        $this->conSaldo(0, 5);

        $id = $this->crearBorrador(['3001112233'], [
            'scheduled_at' => '2026-08-07 12:30:00',
        ])->json('data.id');

        // Media hora después: la ventana pasó. (No se avanza más porque el JWT
        // de la sesión caduca a las dos horas y la petición daría 401.)
        Carbon::setTestNow('2026-08-07 13:00:00');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(SendCampaignJob::class, function (SendCampaignJob $job) {
            $this->assertNull($job->delay, 'La fecha ya pasó: sale ya.');

            return true;
        });
    }

    public function test_lo_que_gasta_una_campanna_ya_no_esta_para_la_siguiente(): void
    {
        // El descuento es real, no un apunte: con saldo para dos mensajes, la
        // primera campaña se los lleva y la segunda se queda sin enviar.
        $this->conSaldo(0, 2);

        $primera = $this->crearBorrador(['3001112233', '3004445566'])->json('data.id');
        $segunda = $this->crearBorrador(['3007778899'])->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$primera}/send")->assertStatus(200);

        $this->assertSaldo(0, 0);

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$segunda}/send")
            ->assertStatus(422)
            ->assertJsonPath('credits.whatsapp.needed', 1)
            ->assertJsonPath('credits.whatsapp.available', 0)
            ->assertJsonPath('credits.whatsapp.missing', 1);

        Queue::assertPushed(SendCampaignJob::class, 1);
        $this->assertSame('draft', Campaign::findOrFail($segunda)->status);
    }

    public function test_cancelar_una_programada_la_detiene_pero_no_devuelve_los_creditos(): void
    {
        // Decisión consciente de esta spec: el reembolso queda como follow-up.
        // Cancelar detiene el envío —el job comprueba el estado antes de
        // arrancar (Spec 0038)— pero lo cobrado sigue cobrado.
        $this->conSaldo(0, 5);

        $id = $this->crearBorrador(['3001112233', '3004445566'], [
            'scheduled_at' => '2026-08-20 09:00:00',
        ])->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(200);
        $this->assertSaldo(0, 3);

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/cancel")->assertStatus(200);

        $this->assertSame('cancelled', Campaign::findOrFail($id)->status);
        $this->assertSame(2, CampaignRecipient::where('status', 'cancelled')->count());

        // Sin reembolso.
        $this->assertSaldo(0, 3);
        $this->assertSame(1, MessagingCreditTransaction::count());
    }

    public function test_un_envio_que_no_llega_a_cobrarse_no_deja_rastro(): void
    {
        // Ni destinatarios escritos, ni `queued_at`, ni transacción: el borrador
        // queda exactamente como estaba y se puede corregir y reintentar.
        $this->conSaldo(0, 1);

        $id = $this->crearBorrador(['3001112233', '3004445566'])->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(422);

        $campana = Campaign::findOrFail($id);

        $this->assertSame('draft', $campana->status);
        $this->assertNull($campana->queued_at);
        $this->assertSame(0, $campana->total_recipients);
        $this->assertSame(0, CampaignRecipient::count());
        $this->assertDatabaseCount('messaging_credit_transactions', 0);

        // Corregido el filtro, el mismo borrador sale.
        $this->comoUsuario()->putJson("/api/v1/campaigns/{$id}", [
            'filter_json' => $this->listaPersonalizada(['3001112233']),
        ])->assertStatus(200);

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(200);

        $this->assertSaldo(0, 0);
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
    private function crearBorrador(array $telefonos, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->comoUsuario()->postJson('/api/v1/campaigns', array_merge([
            'title' => 'Invitación a la asamblea',
            'message' => 'Te esperamos el sábado en el salón comunal.',
            'channel' => 'whatsapp',
            'filter_json' => $this->listaPersonalizada($telefonos),
        ], $extra));
    }

    /**
     * @param  array<int, string>  $telefonos
     * @return array<string, mixed>
     */
    private function listaPersonalizada(array $telefonos): array
    {
        return [
            'target' => 'custom_list',
            'custom_recipients' => array_map(
                fn (string $telefono) => ['type' => 'phone', 'value' => $telefono],
                $telefonos
            ),
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

    private function assertSaldo(int $correos, int $whatsapp): void
    {
        $credito = TenantMessagingCredit::where('tenant_id', $this->tenant->id)->firstOrFail();

        $this->assertSame($correos, $credito->emails_available, 'Saldo de correo.');
        $this->assertSame($whatsapp, $credito->whatsapp_available, 'Saldo de WhatsApp.');
    }
}
