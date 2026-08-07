<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\Campaigns\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CampaignService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `campaigns.creator_token` no sale del servidor (Spec 0039, Art. VII).
 *
 * El alta de una campaña guarda un JWT del creador —con vigencia de un año— en
 * `campaigns.creator_token`, porque el webhook de correo de n8n lo reutiliza como
 * `Bearer` al enviar. La caracterización 0013 encontró que ese token, además de
 * vivir en claro en la tabla, **se copiaba al registro de auditoría**: `Campaign`
 * es `Auditable` y no declaraba `$auditExclude`, así que la credencial acababa
 * también en `audits.new_values`, consultable por API con `view_audits`.
 *
 * Esta spec lo saca de la auditoría (`$auditExclude`) y de cualquier
 * serialización del modelo (`$hidden`). **No cambia cómo se genera ni cómo se
 * usa**: sustituir el JWT de un año por un token de vida corta es una decisión
 * aparte, anotada como follow-up.
 *
 * `owen-it/laravel-auditing` se apaga solo cuando la app corre en consola
 * (`config('audit.console')`), que es el caso de la suite, así que estas pruebas
 * lo encienden a propósito para ejercitar el registro de verdad.
 */
class CampaignCreatorTokenTest extends TestCase
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

        // La auditoría se desactiva en consola; aquí interesa que corra.
        config(['audit.console' => true]);

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
    // Auditoría
    // ==================================================================

    public function test_crear_una_campanna_no_deja_el_token_en_la_auditoria(): void
    {
        $id = $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload())->json('data.id');

        $registro = $this->auditoriaDe($id, 'created');

        // La auditoría corrió de verdad —si no, la prueba no probaría nada—...
        $this->assertArrayHasKey('title', $registro);
        $this->assertSame('Invitación a la asamblea', $registro['title']);

        // ...y el token no está en ella.
        $this->assertArrayNotHasKey('creator_token', $registro);

        // El token sí sigue guardado en la campaña: el webhook lo necesita.
        $this->assertNotEmpty(DB::table('campaigns')->where('id', $id)->value('creator_token'));
    }

    public function test_editar_una_campanna_tampoco_lo_deja_en_la_auditoria(): void
    {
        $id = $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload())->json('data.id');

        $this->comoUsuario()->putJson("/api/v1/campaigns/{$id}", ['title' => 'Título corregido'])
            ->assertStatus(200);

        $registro = $this->auditoriaDe($id, 'updated');

        $this->assertSame('Título corregido', $registro['title']);
        $this->assertArrayNotHasKey('creator_token', $registro);
    }

    public function test_ninguna_auditoria_de_campannas_guarda_el_token(): void
    {
        // Barrido sobre todo lo auditado, sin depender de qué evento sea: el
        // token no puede aparecer en ningún valor, viejo o nuevo.
        $id = $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload())->json('data.id');

        $this->comoUsuario()->putJson("/api/v1/campaigns/{$id}", ['message' => 'Otro mensaje'])
            ->assertStatus(200);

        $filas = DB::table('audits')->get();

        $this->assertNotEmpty($filas, 'La auditoría tiene que estar registrando.');

        foreach ($filas as $fila) {
            $this->assertStringNotContainsString('creator_token', (string) $fila->old_values);
            $this->assertStringNotContainsString('creator_token', (string) $fila->new_values);
        }
    }

    // ==================================================================
    // Respuestas de la API
    // ==================================================================

    public function test_el_token_no_viaja_en_ninguna_respuesta_del_modulo(): void
    {
        $creacion = $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload());
        $id = $creacion->json('data.id');

        $tokenGuardado = DB::table('campaigns')->where('id', $id)->value('creator_token');

        $respuestas = [
            'store' => $creacion,
            'index' => $this->comoUsuario()->getJson('/api/v1/campaigns?include=createdBy,recipients'),
            'show' => $this->comoUsuario()->getJson("/api/v1/campaigns/{$id}"),
            'update' => $this->comoUsuario()->putJson("/api/v1/campaigns/{$id}", ['title' => 'Otro']),
            'recipients' => $this->comoUsuario()->getJson("/api/v1/campaigns/{$id}/recipients"),
        ];

        foreach ($respuestas as $nombre => $respuesta) {
            $cuerpo = $respuesta->getContent();

            $this->assertStringNotContainsString('creator_token', $cuerpo, "El endpoint {$nombre} nombra el token.");
            $this->assertStringNotContainsString($tokenGuardado, $cuerpo, "El endpoint {$nombre} filtra el token.");
        }
    }

    public function test_el_modelo_serializado_oculta_el_token_pero_el_codigo_lo_sigue_leyendo(): void
    {
        // `$hidden` es la red de seguridad para cualquier sitio que serialice el
        // modelo sin pasar por `CampaignResource` (una relación cargada, un
        // `toArray()` suelto, una respuesta cruda). No afecta al acceso por
        // atributo, que es como lo lee el servicio de correo.
        $id = $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload())->json('data.id');

        $campana = Campaign::findOrFail($id);

        $this->assertArrayNotHasKey('creator_token', $campana->toArray());
        $this->assertStringNotContainsString('creator_token', $campana->toJson());
        $this->assertNotEmpty($campana->creator_token);
    }

    public function test_hallazgo_el_token_guardado_no_dura_un_anno_sino_el_ttl_normal(): void
    {
        // `CampaignService` sube `config('jwt.ttl')` a 525.600 minutos (un año),
        // genera el token y restaura el valor. **No funciona**: la factoría de
        // JWT ya está resuelta con el TTL de la configuración, así que el token
        // que se guarda dura lo de siempre.
        //
        // Rebaja el riesgo de esta spec —la credencial caduca en horas, no en un
        // año— pero rompe justo aquello para lo que se escribió: una campaña
        // programada para dentro de más tiempo que el TTL llegará al webhook con
        // un token caducado. Queda como hallazgo; arreglarlo es el follow-up de
        // la 0039, que exige decidir el mecanismo.
        $id = $this->comoUsuario()->postJson('/api/v1/campaigns', $this->payload())->json('data.id');

        $token = DB::table('campaigns')->where('id', $id)->value('creator_token');
        $reclamos = json_decode(
            base64_decode(strtr(explode('.', $token)[1], '-_', '+/')),
            true
        );

        $minutos = ($reclamos['exp'] - $reclamos['iat']) / 60;

        $this->assertSame((float) config('jwt.ttl'), (float) $minutos, 'Dura el TTL normal de la app.');
        $this->assertLessThan(525600, $minutos, 'No es el año que el código pretende.');
    }

    // ==================================================================
    // El correo sigue funcionando
    // ==================================================================

    public function test_el_correo_a_n8n_sigue_autenticandose_con_el_token_de_la_campanna(): void
    {
        // RF-3: ocultarlo no puede romper el envío. El token viaja en la
        // cabecera hacia n8n, que es su único uso legítimo.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $campana = Campaign::factory()->forTenant($this->tenant)->create([
            'created_by' => $this->user->id,
            'channel' => 'email',
            'creator_token' => 'token-de-un-anno',
            'total_recipients' => 1,
        ]);

        CampaignRecipient::create([
            'campaign_id' => $campana->id,
            'recipient_type' => 'email',
            'recipient_value' => 'ana@ejemplo.test',
            'status' => 'pending',
        ]);

        (new SendCampaignJob($campana))->handle(app(CampaignService::class));

        Http::assertSent(function ($request) {
            $this->assertSame('Bearer token-de-un-anno', $request->header('Authorization')[0]);
            $this->assertSame('ana@ejemplo.test', $request['email']);

            return true;
        });

        $this->assertSame('sent', CampaignRecipient::firstOrFail()->status);
    }

    // ------------------------------------------------------------------

    private function comoUsuario(): static
    {
        return $this->actingAsTenantUser($this->user, $this->token);
    }

    /**
     * `new_values` del registro de auditoría de esa campaña y ese evento.
     *
     * @return array<string, mixed>
     */
    private function auditoriaDe(int $campaignId, string $evento): array
    {
        // El más reciente: el alta genera además un `updated` propio, porque el
        // servicio guarda `total_recipients` justo después de crear.
        $fila = DB::table('audits')
            ->where('auditable_type', Campaign::class)
            ->where('auditable_id', $campaignId)
            ->where('event', $evento)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($fila, "No hay auditoría `{$evento}` de la campaña {$campaignId}.");

        return json_decode((string) $fila->new_values, true) ?? [];
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
