<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN del flujo público de QR (Spec 0010).
 *
 * Tres rutas SIN autenticación:
 *   GET  /meetings/public/{qr_code}    → getPublicInfo
 *   GET  /meetings/check-in/{qr_code}  → showByQR
 *   POST /meetings/check-in/{qr_code}  → checkIn
 *
 * Al no pasar por `tenant`, no hay `current_tenant_id` enlazado y `TenantScope`
 * no filtra: la reunión se busca por su código en toda la base. Es lo que hace
 * falta para que un QR impreso funcione sin sesión, pero conviene tenerlo
 * presente.
 */
class MeetingPublicCheckInTest extends TestCase
{
    private Tenant $tenant;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-06 09:00:00');

        $this->tenant = Tenant::factory()->create();
        $this->meeting = Meeting::factory()->forTenant($this->tenant)->create([
            'title' => 'Asamblea barrial',
            'description' => 'Encuentro con la comunidad',
            'qr_code' => 'QR-PUBLICO-1',
            'lugar_nombre' => 'Salón comunal',
            'starts_at' => '2026-08-20 18:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // getPublicInfo
    // ------------------------------------------------------------------

    public function test_la_info_publica_responde_sin_token(): void
    {
        $response = $this->getJson('/api/v1/meetings/public/QR-PUBLICO-1');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->meeting->id)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id', 'starts_at', 'status', 'lugar_nombre',
                    'planner', 'location', 'attendees_count', 'checked_in_count',
                ],
            ]);
    }

    public function test_rareza_la_info_publica_devuelve_null_en_titulo_y_descripcion(): void
    {
        // El controller lee `titulo`, `descripcion`, `objetivo`, `lugar_tipo`,
        // `lugar_direccion` y `lugar_url`, que NO existen en el modelo Meeting
        // (son `title`, `description`, `direccion`; los otros tres no existen).
        // Resultado: la pantalla pública de check-in recibe el título en null.
        $data = $this->getJson('/api/v1/meetings/public/QR-PUBLICO-1')
            ->assertStatus(200)
            ->json('data');

        $this->assertNull($data['titulo'], 'El campo real del modelo es `title`.');
        $this->assertNull($data['descripcion'], 'El campo real del modelo es `description`.');
        $this->assertNull($data['objetivo']);
        $this->assertNull($data['lugar_tipo']);
        $this->assertNull($data['lugar_direccion'], 'El campo real del modelo es `direccion`.');
        $this->assertNull($data['lugar_url']);

        // Estos sí coinciden con el modelo y llegan bien.
        $this->assertSame('Salón comunal', $data['lugar_nombre']);
        $this->assertSame('scheduled', $data['status']);
    }

    public function test_la_info_publica_cuenta_asistentes_y_check_ins(): void
    {
        $this->crearAsistente('71000001', true);
        $this->crearAsistente('71000002', true);
        $this->crearAsistente('71000003', false);

        $this->getJson('/api/v1/meetings/public/QR-PUBLICO-1')
            ->assertStatus(200)
            ->assertJsonPath('data.attendees_count', 3)
            ->assertJsonPath('data.checked_in_count', 2);
    }

    public function test_la_info_publica_con_un_qr_inexistente_da_404(): void
    {
        $this->getJson('/api/v1/meetings/public/QR-QUE-NO-EXISTE')->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // showByQR
    // ------------------------------------------------------------------

    public function test_show_por_qr_devuelve_la_reunion_completa_sin_token(): void
    {
        $this->getJson('/api/v1/meetings/check-in/QR-PUBLICO-1')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $this->meeting->id)
            ->assertJsonPath('data.title', 'Asamblea barrial')
            ->assertJsonPath('data.qr_code', 'QR-PUBLICO-1');
    }

    public function test_rareza_show_por_qr_expone_datos_internos_en_una_ruta_publica(): void
    {
        // Devuelve el MeetingResource entero: incluye `tenant_id` y el objeto
        // `planner` con su email. Cualquiera con el código del QR los ve.
        $data = $this->getJson('/api/v1/meetings/check-in/QR-PUBLICO-1')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame($this->tenant->id, $data['tenant_id']);
        $this->assertArrayHasKey('planner', $data);
        $this->assertArrayHasKey('email', $data['planner']);
    }

    public function test_show_por_qr_con_codigo_invalido_da_404(): void
    {
        $this->getJson('/api/v1/meetings/check-in/QR-QUE-NO-EXISTE')->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // checkIn
    // ------------------------------------------------------------------

    public function test_el_check_in_registra_al_asistente_sin_token(): void
    {
        $response = $this->postJson('/api/v1/meetings/check-in/QR-PUBLICO-1', [
            'cedula' => '71000001',
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
            'telefono' => '3001234567',
            'email' => 'ana@ejemplo.test',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Check-in successful')
            ->assertJsonPath('data.cedula', '71000001')
            ->assertJsonPath('data.checked_in', true);

        $this->assertDatabaseHas('meeting_attendees', [
            'meeting_id' => $this->meeting->id,
            'tenant_id' => $this->tenant->id,
            'cedula' => '71000001',
            'checked_in' => true,
        ]);
    }

    public function test_el_check_in_marca_la_hora_y_deja_created_by_nulo(): void
    {
        $this->postJson('/api/v1/meetings/check-in/QR-PUBLICO-1', $this->payloadCheckIn())
            ->assertStatus(201);

        $asistente = MeetingAttendee::withoutGlobalScope(TenantScope::class)->firstOrFail();

        $this->assertSame('2026-08-06 09:00:00', $asistente->checked_in_at->toDateTimeString());
        // Nadie autenticado detrás de un QR público.
        $this->assertNull($asistente->created_by);
    }

    public function test_el_check_in_hereda_el_tenant_de_la_reunion(): void
    {
        $otro = Tenant::factory()->create();
        Meeting::factory()->forTenant($otro)->create(['qr_code' => 'QR-DE-OTRO-TENANT']);

        $this->postJson('/api/v1/meetings/check-in/QR-DE-OTRO-TENANT', $this->payloadCheckIn())
            ->assertStatus(201);

        $this->assertDatabaseHas('meeting_attendees', [
            'cedula' => '71000001',
            'tenant_id' => $otro->id,
        ]);
    }

    public function test_el_check_in_valida_los_campos_obligatorios(): void
    {
        $this->postJson('/api/v1/meetings/check-in/QR-PUBLICO-1', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cedula', 'nombres', 'apellidos']);
    }

    public function test_el_check_in_valida_el_formato_del_email(): void
    {
        $this->postJson('/api/v1/meetings/check-in/QR-PUBLICO-1', $this->payloadCheckIn([
            'email' => 'no-es-un-email',
        ]))->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_el_check_in_guarda_los_campos_dinamicos(): void
    {
        // `extra_fields` es JSON libre: lo que la plantilla de la reunión pida.
        $this->postJson('/api/v1/meetings/check-in/QR-PUBLICO-1', $this->payloadCheckIn([
            'extra_fields' => ['profesion' => 'Docente', 'acepta_datos' => true],
        ]))->assertStatus(201)->assertJsonPath('data.extra_fields.profesion', 'Docente');

        $asistente = MeetingAttendee::withoutGlobalScope(TenantScope::class)->firstOrFail();

        $this->assertSame(['profesion' => 'Docente', 'acepta_datos' => true], $asistente->extra_fields);
    }

    public function test_el_check_in_con_un_qr_inexistente_da_404(): void
    {
        $this->postJson('/api/v1/meetings/check-in/QR-QUE-NO-EXISTE', $this->payloadCheckIn())
            ->assertStatus(404);

        $this->assertDatabaseCount('meeting_attendees', 0);
    }

    public function test_rareza_el_check_in_admite_la_misma_cedula_dos_veces(): void
    {
        // No hay comprobación de duplicados: la misma persona puede registrarse
        // tantas veces como escanee el QR, y cada una cuenta como asistente.
        $this->postJson('/api/v1/meetings/check-in/QR-PUBLICO-1', $this->payloadCheckIn())
            ->assertStatus(201);
        $this->postJson('/api/v1/meetings/check-in/QR-PUBLICO-1', $this->payloadCheckIn())
            ->assertStatus(201);

        $this->assertDatabaseCount('meeting_attendees', 2);

        $this->getJson('/api/v1/meetings/public/QR-PUBLICO-1')
            ->assertJsonPath('data.attendees_count', 2);
    }

    public function test_rareza_se_puede_hacer_check_in_en_una_reunion_cancelada_o_completada(): void
    {
        // El estado de la reunión no se mira en ningún momento.
        foreach (['cancelled', 'completed'] as $estado) {
            $this->meeting->update(['status' => $estado]);

            $this->postJson('/api/v1/meetings/check-in/QR-PUBLICO-1', $this->payloadCheckIn([
                'cedula' => '7100'.$estado,
            ]))->assertStatus(201);
        }

        $this->assertDatabaseCount('meeting_attendees', 2);
    }

    public function test_rareza_la_respuesta_del_check_in_no_usa_el_resource(): void
    {
        // `checkIn` devuelve el modelo crudo, no un MeetingAttendeeResource: la
        // forma difiere del resto de endpoints de asistentes (aquí sí viaja
        // `tenant_id`, y no viene `full_name`).
        $data = $this->postJson('/api/v1/meetings/check-in/QR-PUBLICO-1', $this->payloadCheckIn())
            ->assertStatus(201)
            ->json('data');

        $this->assertArrayHasKey('tenant_id', $data);
        $this->assertArrayNotHasKey('full_name', $data);
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payloadCheckIn(array $extra = []): array
    {
        return array_merge([
            'cedula' => '71000001',
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
        ], $extra);
    }

    private function crearAsistente(string $cedula, bool $checkedIn): void
    {
        $this->meeting->attendees()->create([
            'tenant_id' => $this->tenant->id,
            'cedula' => $cedula,
            'nombres' => 'Asistente',
            'apellidos' => $cedula,
            'checked_in' => $checkedIn,
            'checked_in_at' => $checkedIn ? now() : null,
        ]);
    }
}
