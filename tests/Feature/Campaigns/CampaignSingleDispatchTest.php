<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\Campaigns\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
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
 * Una campaña se despacha una sola vez (Spec 0038, Fase 3).
 *
 * Cierra el hallazgo de la 0013: `CampaignService::createCampaign` ya despacha
 * `SendCampaignJob` y deja la campaña en `pending`, que era justo el estado que
 * `POST /campaigns/{id}/send` exigía. Un clic en «enviar» encolaba un **segundo**
 * job de la misma campaña y, si el primero no había corrido, los dos veían
 * destinatarios `pending` y **la gente recibía el mensaje dos veces**.
 *
 * La regla: el alta encola una vez y lo marca con `queued_at`; `send` no vuelve
 * a encolar lo que ya está encolado. Sigue siendo el disparador de las campañas
 * que no llegaron a encolarse.
 *
 * Aquí también vive la protección de `destroy`, que comparaba con `in_progress`
 * —un estado que nadie escribe— en vez de con `sending`.
 */
class CampaignSingleDispatchTest extends TestCase
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
            ['view_campaigns', 'create_campaigns', 'edit_campaigns', 'delete_campaigns'],
            $this->tenant
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ==================================================================
    // Despacho único
    // ==================================================================

    public function test_enviar_encola_una_vez_y_lo_deja_anotado(): void
    {
        Queue::fake();

        $id = $this->crearBorrador()->json('data.id');

        // El alta ya no encola nada: el borrador espera (Spec 0040).
        Queue::assertNothingPushed();

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(200);

        Queue::assertPushed(SendCampaignJob::class, 1);

        $campana = Campaign::findOrFail($id);

        $this->assertNotNull($campana->queued_at, 'El envío anota que ya encoló.');
        $this->assertSame('2026-08-07 12:00:00', $campana->queued_at->toDateTimeString());
    }

    public function test_send_no_encola_un_segundo_envio_de_la_misma_campanna(): void
    {
        Queue::fake();

        $id = $this->crearBorrador()->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(200);

        // Ya no es borrador: el segundo intento no encola.
        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Campaign is not a draft');

        Queue::assertPushed(SendCampaignJob::class, 1);
    }

    public function test_un_borrador_ya_encolado_tampoco_se_vuelve_a_encolar(): void
    {
        // Cinturón y tirantes: si por lo que sea un borrador tuviera despacho
        // anotado, `send` no encola un segundo job.
        Queue::fake();

        $campana = Campaign::factory()->forTenant($this->tenant)->status('draft')->create([
            'created_by' => $this->user->id,
            'queued_at' => now(),
        ]);

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$campana->id}/send")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Campaign was already queued for sending');

        Queue::assertNothingPushed();
    }

    public function test_insistir_en_enviar_no_multiplica_los_mensajes(): void
    {
        // La prueba que de verdad importa: sin cola en falso, cuántos WhatsApp
        // salen de verdad. Antes, dos jobs sobre los mismos destinatarios
        // `pending` los enviaban dos veces.
        Http::fake(['*' => Http::response([], 200)]);
        $this->instanciaWhatsApp();

        $usuario = User::factory()->forTenant($this->tenant)->create(['phone' => '3001112233']);

        $id = $this->crearBorrador()->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(200);

        // El panel insiste tres veces mientras el job todavía no ha corrido.
        foreach (range(1, 3) as $ignorado) {
            $this->comoUsuario()->postJson("/api/v1/campaigns/{$id}/send")->assertStatus(422);
        }

        (new SendCampaignJob(Campaign::findOrFail($id)))->handle(app(CampaignService::class));

        Http::assertSentCount(1);

        $this->assertSame(1, CampaignRecipient::where('status', 'sent')->count());
        $this->assertSame('57'.$usuario->phone, Http::recorded()->first()[0]['number']);
    }

    public function test_send_rechaza_todo_lo_que_no_sea_un_borrador(): void
    {
        Queue::fake();

        foreach (['pending', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'] as $estado) {
            $campana = Campaign::factory()->forTenant($this->tenant)->status($estado)->create([
                'created_by' => $this->user->id,
                'queued_at' => null,
            ]);

            $this->comoUsuario()->postJson("/api/v1/campaigns/{$campana->id}/send")
                ->assertStatus(422)
                ->assertJsonPath('message', 'Campaign is not a draft');
        }

        Queue::assertNothingPushed();
    }

    public function test_el_despacho_unico_no_cruza_tenants(): void
    {
        Queue::fake();

        $ajena = Campaign::factory()->forTenant(Tenant::factory()->create())
            ->status('draft')
            ->create(['queued_at' => null]);

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$ajena->id}/send")->assertStatus(404);

        Queue::assertNothingPushed();
        $this->assertNull($ajena->fresh()->queued_at);
    }

    // ==================================================================
    // destroy
    // ==================================================================

    public function test_no_se_puede_borrar_una_campanna_que_esta_enviando(): void
    {
        $enviando = Campaign::factory()->forTenant($this->tenant)->status('sending')->create([
            'created_by' => $this->user->id,
        ]);

        $this->comoUsuario()->deleteJson("/api/v1/campaigns/{$enviando->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete campaign in progress');

        $this->assertDatabaseHas('campaigns', ['id' => $enviando->id, 'deleted_at' => null]);
    }

    public function test_las_demas_se_siguen_pudiendo_borrar(): void
    {
        foreach (['draft', 'pending', 'scheduled', 'sent', 'failed', 'cancelled'] as $estado) {
            $campana = Campaign::factory()->forTenant($this->tenant)->status($estado)->create([
                'created_by' => $this->user->id,
            ]);

            $this->comoUsuario()->deleteJson("/api/v1/campaigns/{$campana->id}")
                ->assertStatus(200)
                ->assertJsonPath('message', 'Campaign deleted successfully');

            $this->assertSoftDeleted('campaigns', ['id' => $campana->id]);
        }
    }

    // ------------------------------------------------------------------

    private function comoUsuario(): static
    {
        return $this->actingAsTenantUser($this->user, $this->token);
    }

    /**
     * Borrador con un destinatario fijo y saldo de sobra: aquí se prueba el
     * despacho, no el cobro.
     */
    private function crearBorrador(): \Illuminate\Testing\TestResponse
    {
        TenantMessagingCredit::updateOrCreate(
            ['tenant_id' => $this->tenant->id],
            [
                'emails_available' => 100,
                'emails_used' => 0,
                'whatsapp_available' => 100,
                'whatsapp_used' => 0,
            ]
        );

        return $this->comoUsuario()->postJson('/api/v1/campaigns', [
            'title' => 'Invitación a la asamblea',
            'message' => 'Te esperamos el sábado en el salón comunal.',
            'channel' => 'whatsapp',
            'filter_json' => [
                'target' => 'custom_list',
                'custom_recipients' => [['type' => 'phone', 'value' => '3001112233']],
            ],
        ]);
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
