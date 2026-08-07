<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\Campaigns\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Tenant;
use App\Models\TenantWhatsAppInstance;
use App\Models\User;
use App\Services\CampaignService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El envío de una campaña alcanza a TODOS sus destinatarios (Spec 0037).
 *
 * Cierra el hallazgo 🔴 de la caracterización 0013: `SendCampaignJob` recorría
 * `recipients()->where('status','pending')->chunk($n, ...)` y marcaba cada fila
 * como enviada dentro del bucle. `chunk()` pagina con **OFFSET** sobre una
 * consulta cuyo resultado encoge a cada envío, así que a partir del segundo lote
 * el desplazamiento se comía justo las filas que faltaban: quedaban destinatarios
 * en `pending` para siempre y la campaña se cerraba como `sent` igual.
 *
 * Con el `batch_size` por defecto (100) el fallo no se veía hasta los 101
 * destinatarios — precisamente el tamaño a partir del cual el envío masivo tiene
 * sentido. De ahí que aquí se pruebe con lotes de 1 (barato y equivalente) y
 * también con una campaña real de más de cien.
 *
 * Sin red: `Http::fake` + `preventStrayRequests`.
 */
class CampaignSendCompletenessTest extends TestCase
{
    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 12:00:00');

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 200)]);

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant)->create();

        $this->instanciaWhatsApp();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_con_lotes_de_uno_no_se_salta_ningun_destinatario(): void
    {
        // El caso mínimo del hallazgo: antes salían 2 de 3 y el tercero se
        // quedaba en `pending` para siempre.
        config(['campaign.batch_size' => 1]);

        $campana = $this->campannaCon(['3001112233', '3004445566', '3007778899']);

        $this->ejecutar($campana);

        Http::assertSentCount(3);

        $this->assertSame(3, CampaignRecipient::where('status', 'sent')->count());
        $this->assertSame(0, CampaignRecipient::where('status', 'pending')->count());
        $this->assertSame(3, $campana->refresh()->sent_count);
    }

    public function test_una_campanna_de_mas_de_cien_destinatarios_llega_entera(): void
    {
        // Con el tamaño de lote por defecto hacen falta más de 100 destinatarios
        // para cruzar la frontera del segundo lote, que es donde el bug vivía sin
        // que nadie lo viera.
        $numeros = collect(range(1, 120))
            ->map(fn (int $i) => '300'.str_pad((string) $i, 7, '0', STR_PAD_LEFT))
            ->all();

        $campana = $this->campannaCon($numeros);

        $this->ejecutar($campana);

        Http::assertSentCount(120);

        $this->assertSame(120, CampaignRecipient::where('status', 'sent')->count());
        $this->assertSame(0, CampaignRecipient::where('status', 'pending')->count());

        $campana->refresh();

        $this->assertSame(120, $campana->sent_count);
        $this->assertSame($campana->total_recipients, $campana->sent_count);
        $this->assertSame('sent', $campana->status);
    }

    public function test_cada_destinatario_recibe_el_mensaje_exactamente_una_vez(): void
    {
        config(['campaign.batch_size' => 2]);

        $numeros = ['3001112233', '3004445566', '3007778899', '3002223344', '3005556677'];

        $this->ejecutar($this->campannaCon($numeros));

        $enviados = [];

        Http::assertSent(function ($request) use (&$enviados) {
            $enviados[] = $request['number'];

            return true;
        });

        // El servicio normaliza el número colombiano de 10 dígitos con el 57.
        $esperados = array_map(fn (string $numero) => '57'.$numero, $numeros);

        sort($enviados);
        sort($esperados);

        $this->assertSame($esperados, $enviados, 'Ni uno de menos ni uno repetido.');
    }

    public function test_la_campanna_solo_se_cierra_cuando_no_queda_ningun_pendiente(): void
    {
        config(['campaign.batch_size' => 1]);

        $campana = $this->campannaCon(['3001112233', '3004445566']);

        $this->ejecutar($campana);

        $this->assertSame(0, $campana->recipients()->where('status', 'pending')->count());
        $this->assertSame('sent', $campana->refresh()->status);
    }

    public function test_los_envios_fallidos_tampoco_dejan_a_nadie_pendiente(): void
    {
        // Sin instancia de WhatsApp todos fallan. Fallar es un desenlace: lo que
        // no puede quedar es alguien sin intentar.
        TenantWhatsAppInstance::query()->delete();
        config(['campaign.batch_size' => 1]);

        $campana = $this->campannaCon(['3001112233', '3004445566', '3007778899']);

        $this->ejecutar($campana);

        $this->assertSame(3, CampaignRecipient::where('status', 'failed')->count());
        $this->assertSame(0, CampaignRecipient::where('status', 'pending')->count());
        $this->assertSame(3, $campana->refresh()->failed_count);
    }

    public function test_reejecutar_el_job_no_reenvia_a_quien_ya_recibio(): void
    {
        config(['campaign.batch_size' => 1]);

        $campana = $this->campannaCon(['3001112233', '3004445566']);

        $this->ejecutar($campana);

        Http::assertSentCount(2);

        // Aunque alguien devuelva la campaña a `pending` y se vuelva a encolar,
        // los destinatarios ya enviados no entran: la consulta solo mira los
        // `pending`.
        $campana->update(['status' => 'pending']);

        $this->ejecutar($campana);

        Http::assertSentCount(2);

        $this->assertSame(2, CampaignRecipient::where('status', 'sent')->count());
    }

    // ------------------------------------------------------------------

    private function ejecutar(Campaign $campana): void
    {
        (new SendCampaignJob($campana))->handle(app(CampaignService::class));
    }

    /**
     * Campaña `pending` con sus destinatarios de WhatsApp ya resueltos.
     *
     * @param  array<int, string>  $numeros
     */
    private function campannaCon(array $numeros): Campaign
    {
        $campana = Campaign::factory()->forTenant($this->tenant)->create([
            'created_by' => $this->user->id,
            'channel' => 'whatsapp',
            'total_recipients' => count($numeros),
        ]);

        $ahora = now();

        CampaignRecipient::insert(array_map(fn (string $numero) => [
            'campaign_id' => $campana->id,
            'recipient_type' => 'whatsapp',
            'recipient_value' => $numero,
            'status' => 'pending',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], $numeros));

        return $campana;
    }

    private function instanciaWhatsApp(): TenantWhatsAppInstance
    {
        return TenantWhatsAppInstance::create([
            'tenant_id' => $this->tenant->id,
            'phone_number' => '573000000000',
            'instance_name' => 'instancia-1',
            'evolution_api_key' => 'clave-de-prueba',
            'evolution_api_url' => 'https://evolution.test',
            'daily_message_limit' => 100000,
            'messages_sent_today' => 0,
            'last_reset_date' => Carbon::today(),
            'is_active' => true,
        ]);
    }
}
