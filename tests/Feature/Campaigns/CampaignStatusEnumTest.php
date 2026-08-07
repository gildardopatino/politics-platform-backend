<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * Los estados que el código escribe caben en la columna (Spec 0038, Fase 1).
 *
 * La caracterización 0013 encontró dos formas del mismo problema: el
 * vocabulario de estados del código y el CHECK de la columna vivían cada uno por
 * su lado. `cancel` escribía `cancelled`, que no existía en el enum, así que
 * respondía **500** y la campaña no se cancelaba.
 *
 * Estas pruebas fijan el juego completo por los dos lados —lo que debe caber y
 * lo que no— para que la próxima divergencia salte aquí y no en producción.
 */
class CampaignStatusEnumTest extends TestCase
{
    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant)->create();
    }

    public function test_la_columna_admite_todos_los_estados_que_el_codigo_escribe(): void
    {
        $estados = ['draft', 'pending', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'];

        foreach ($estados as $estado) {
            $campana = $this->crearCampanna($estado);

            $this->assertSame($estado, $campana->fresh()->status, "El estado {$estado} no cabe en la columna.");
        }

        $this->assertSame(count($estados), Campaign::count());
    }

    public function test_la_columna_sigue_rechazando_los_estados_fantasma(): void
    {
        // `in_progress` y `completed` nunca los escribió nadie: eran los que
        // comprobaban las guardas de `destroy` y `cancel`, y por eso no protegían
        // nada. No se añaden al enum: se corrigen las guardas.
        foreach (['in_progress', 'completed'] as $fantasma) {
            try {
                $this->crearCampanna($fantasma);
                $this->fail("El estado {$fantasma} no debería caber en la columna.");
            } catch (QueryException $e) {
                $this->assertStringContainsStringIgnoringCase('constraint', $e->getMessage());
            }
        }
    }

    public function test_el_destinatario_admite_cancelado_ademas_de_sus_desenlaces(): void
    {
        $campana = $this->crearCampanna('pending');

        foreach (['pending', 'sent', 'failed', 'bounced', 'cancelled'] as $estado) {
            $destinatario = CampaignRecipient::create([
                'campaign_id' => $campana->id,
                'recipient_type' => 'whatsapp',
                'recipient_value' => '300111'.$estado,
                'status' => $estado,
            ]);

            $this->assertSame($estado, $destinatario->fresh()->status, "El estado {$estado} no cabe.");
        }
    }

    public function test_cancelar_una_campanna_ya_no_revienta_contra_la_columna(): void
    {
        // La comprobación de más bajo nivel: antes de la 0038 este `update`
        // lanzaba una violación del CHECK, que es lo que convertía `cancel` en
        // un 500.
        $campana = $this->crearCampanna('pending');

        $campana->update(['status' => 'cancelled']);

        $this->assertSame('cancelled', $campana->fresh()->status);
    }

    // ------------------------------------------------------------------

    private function crearCampanna(string $estado): Campaign
    {
        return Campaign::factory()->forTenant($this->tenant)->create([
            'created_by' => $this->user->id,
            'status' => $estado,
        ]);
    }
}
