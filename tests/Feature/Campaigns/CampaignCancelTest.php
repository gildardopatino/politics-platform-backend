<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\Campaigns\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CampaignService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cancelar una campaña (Spec 0038, Fase 2).
 *
 * Cierra el hallazgo de la 0013: `cancel` escribía `status = 'cancelled'`, valor
 * que el CHECK de la columna no admitía, así que respondía **500** y la campaña
 * seguía como estaba. Y su guarda comparaba con `completed` —un estado que nadie
 * escribe— en vez de con `sent`, de modo que ni una campaña ya enviada se
 * frenaba antes del error.
 *
 * Qué hace ahora: la campaña pasa a `cancelled` y los destinatarios que seguían
 * `pending` se marcan como cancelados. Los que ya salieron no se tocan: cancelar
 * detiene lo que falta, no reescribe lo que pasó.
 */
class CampaignCancelTest extends TestCase
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
            ['view_campaigns', 'edit_campaigns'],
            $this->tenant
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_cancelar_marca_la_campanna_y_los_destinatarios_que_faltaban(): void
    {
        $campana = $this->campanna('pending');
        $this->destinatario($campana, '3001112233', 'pending');
        $this->destinatario($campana, '3004445566', 'pending');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$campana->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Campaign cancelled')
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', $campana->fresh()->status);
        $this->assertSame(2, CampaignRecipient::where('status', 'cancelled')->count());
        $this->assertSame(0, CampaignRecipient::where('status', 'pending')->count());
    }

    public function test_cancelar_no_reescribe_a_quien_ya_recibio_el_mensaje(): void
    {
        // Cancelar detiene lo que falta; lo que ya salió, salió.
        $campana = $this->campanna('sending');
        $this->destinatario($campana, '3001112233', 'sent');
        $this->destinatario($campana, '3004445566', 'failed');
        $this->destinatario($campana, '3007778899', 'pending');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$campana->id}/cancel")->assertStatus(200);

        $this->assertSame(1, CampaignRecipient::where('status', 'sent')->count());
        $this->assertSame(1, CampaignRecipient::where('status', 'failed')->count());
        $this->assertSame(1, CampaignRecipient::where('status', 'cancelled')->count());
    }

    public function test_no_se_puede_cancelar_una_campanna_ya_enviada(): void
    {
        $enviada = $this->campanna('sent');
        $this->destinatario($enviada, '3001112233', 'sent');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$enviada->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot cancel a campaign that was already sent');

        $this->assertSame('sent', $enviada->fresh()->status);
        $this->assertSame('sent', CampaignRecipient::firstOrFail()->status);
    }

    public function test_se_puede_cancelar_una_programada_una_en_curso_y_una_fallida(): void
    {
        foreach (['draft', 'pending', 'scheduled', 'sending', 'failed'] as $estado) {
            $campana = $this->campanna($estado);

            $this->comoUsuario()->postJson("/api/v1/campaigns/{$campana->id}/cancel")
                ->assertStatus(200);

            $this->assertSame('cancelled', $campana->fresh()->status, "No se pudo cancelar una {$estado}.");
        }
    }

    public function test_cancelar_una_campanna_programada_impide_que_el_job_la_envie(): void
    {
        // El valor real de cancelar: el job comprueba el estado antes de
        // arrancar, así que la campaña cancelada ya no sale cuando llegue su
        // hora. Sin esto, cancelar no detenía nada.
        Http::fake();

        $campana = $this->campanna('scheduled');
        $this->destinatario($campana, '3001112233', 'pending');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$campana->id}/cancel")->assertStatus(200);

        (new SendCampaignJob($campana->fresh()))->handle(app(CampaignService::class));

        Http::assertNothingSent();
        $this->assertSame('cancelled', $campana->fresh()->status);
        $this->assertSame('cancelled', CampaignRecipient::firstOrFail()->status);
    }

    public function test_cancelar_dos_veces_no_rompe_nada(): void
    {
        $campana = $this->campanna('pending');
        $this->destinatario($campana, '3001112233', 'pending');

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$campana->id}/cancel")->assertStatus(200);
        $this->comoUsuario()->postJson("/api/v1/campaigns/{$campana->id}/cancel")->assertStatus(200);

        $this->assertSame('cancelled', $campana->fresh()->status);
        $this->assertSame(1, CampaignRecipient::where('status', 'cancelled')->count());
    }

    public function test_cancelar_exige_permiso_y_no_cruza_tenants(): void
    {
        $campana = $this->campanna('pending');

        [$pelado, $tokenPelado] = $this->createTenantWithUser([], $this->tenant);

        $this->actingAsTenantUser($pelado, $tokenPelado)
            ->postJson("/api/v1/campaigns/{$campana->id}/cancel")
            ->assertStatus(403);

        $ajena = Campaign::factory()->forTenant(Tenant::factory()->create())->create();

        $this->comoUsuario()->postJson("/api/v1/campaigns/{$ajena->id}/cancel")->assertStatus(404);

        $this->assertSame('pending', $ajena->fresh()->status);
        $this->assertSame('pending', $campana->fresh()->status);
    }

    // ------------------------------------------------------------------

    private function comoUsuario(): static
    {
        return $this->actingAsTenantUser($this->user, $this->token);
    }

    private function campanna(string $estado): Campaign
    {
        return Campaign::factory()->forTenant($this->tenant)->create([
            'created_by' => $this->user->id,
            'channel' => 'whatsapp',
            'status' => $estado,
        ]);
    }

    private function destinatario(Campaign $campana, string $valor, string $estado): CampaignRecipient
    {
        return CampaignRecipient::create([
            'campaign_id' => $campana->id,
            'recipient_type' => 'whatsapp',
            'recipient_value' => $valor,
            'status' => $estado,
        ]);
    }
}
