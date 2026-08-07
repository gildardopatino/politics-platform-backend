<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Los `extra_fields` del check-in se validan contra la plantilla (Spec 0023).
 *
 * Cierra el hallazgo F2 de la caracterización 0010: `extra_fields` se validaba
 * como `nullable|array` y nada más, así que la obligatoriedad la aplicaba solo
 * el formulario del frontend —y llamar a la API la saltaba—. Se aceptaba un
 * campo que la plantilla no declara y se podía omitir uno marcado `required`.
 *
 * La regla vive en `App\Rules\CamposDeLaPlantilla` y es la MISMA para las dos
 * vías de alta: el check-in público por QR y el alta autenticada desde el panel.
 */
class CheckInCamposDinamicosTest extends TestCase
{
    private Tenant $tenant;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 09:00:00');

        // PISAMI es una API externa: el check-in la consulta al crear una
        // persona nueva (Spec 0022) y nunca se llama de verdad desde la suite.
        Http::preventStrayRequests();
        Http::fake(['*pisami*' => Http::response('', 500)]);

        $this->tenant = Tenant::factory()->create();
        $this->meeting = Meeting::factory()->forTenant($this->tenant)->create([
            'qr_code' => 'QR-CAMPOS',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ==================================================================
    // Check-in público por QR
    // ==================================================================

    public function test_un_campo_que_la_plantilla_no_declara_se_rechaza(): void
    {
        // RF-1. Antes esto respondía 201 y guardaba el campo inventado.
        $this->conPlantilla([
            ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => false],
        ]);

        $respuesta = $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'extra_fields' => ['campo_inventado' => 'lo que sea'],
        ]));

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['extra_fields']);

        $this->assertStringContainsString(
            'no está declarado',
            implode(' ', $respuesta->json('errors.extra_fields'))
        );

        $this->assertDatabaseCount('meeting_attendees', 0);
    }

    public function test_omitir_un_campo_obligatorio_de_la_plantilla_se_rechaza(): void
    {
        // RF-2, con el resto de campos presentes.
        $this->conPlantilla([
            ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => true],
            ['name' => 'observaciones', 'label' => 'Observaciones', 'type' => 'textarea', 'required' => false],
        ]);

        $respuesta = $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'extra_fields' => ['observaciones' => 'Vino con su familia'],
        ]));

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['extra_fields']);

        // Art. IX: el mensaje llega en español y nombra el campo por su etiqueta.
        $this->assertContains(
            'El campo «Profesión» es obligatorio.',
            $respuesta->json('errors.extra_fields')
        );

        $this->assertDatabaseCount('meeting_attendees', 0);
    }

    public function test_omitir_extra_fields_entero_no_salta_los_obligatorios(): void
    {
        // La forma más fácil de saltarse la validación era no mandar la clave.
        $this->conPlantilla([
            ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => true],
        ]);

        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['extra_fields']);

        $this->assertDatabaseCount('meeting_attendees', 0);
    }

    public function test_un_campo_obligatorio_en_blanco_cuenta_como_ausente(): void
    {
        // Caso borde de la spec: mandar la clave con "" no cumple el required.
        $this->conPlantilla([
            ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => true],
        ]);

        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'extra_fields' => ['profesion' => ''],
        ]))->assertStatus(422)->assertJsonValidationErrors(['extra_fields']);
    }

    public function test_un_valor_fuera_de_las_opciones_se_rechaza(): void
    {
        // RF-3.
        $this->conPlantilla([
            [
                'name' => 'estrato',
                'label' => 'Estrato socioeconómico',
                'type' => 'radio',
                'required' => true,
                'options' => ['Estrato 1', 'Estrato 2', 'Estrato 3'],
            ],
        ]);

        $respuesta = $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'extra_fields' => ['estrato' => 'Estrato 9'],
        ]));

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['extra_fields']);

        $this->assertStringContainsString(
            'no es una opción válida',
            implode(' ', $respuesta->json('errors.extra_fields'))
        );

        $this->assertDatabaseCount('meeting_attendees', 0);
    }

    public function test_un_check_in_valido_se_registra(): void
    {
        // RF-4: todos los obligatorios, sin campos de más.
        $this->conPlantilla([
            [
                'name' => 'estrato',
                'label' => 'Estrato socioeconómico',
                'type' => 'select',
                'required' => true,
                'options' => ['Estrato 1', 'Estrato 2'],
            ],
            ['name' => 'observaciones', 'label' => 'Observaciones', 'type' => 'textarea', 'required' => false],
        ]);

        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'extra_fields' => ['estrato' => 'Estrato 2'],
        ]))->assertStatus(201);

        $asistente = MeetingAttendee::withoutGlobalScope(TenantScope::class)->firstOrFail();

        $this->assertSame(['estrato' => 'Estrato 2'], $asistente->extra_fields);
    }

    public function test_un_checkbox_de_seleccion_multiple_acepta_varias_opciones(): void
    {
        $this->conPlantilla([
            [
                'name' => 'intereses',
                'label' => 'Temas de interés',
                'type' => 'checkbox',
                'required' => true,
                'options' => ['Educación', 'Salud', 'Vías'],
            ],
        ]);

        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'extra_fields' => ['intereses' => ['Educación', 'Vías']],
        ]))->assertStatus(201);

        // Y basta con que una de las marcadas no exista para rechazarlo.
        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'cedula' => '71000002',
            'extra_fields' => ['intereses' => ['Educación', 'Turismo espacial']],
        ]))->assertStatus(422)->assertJsonValidationErrors(['extra_fields']);

        $this->assertDatabaseCount('meeting_attendees', 1);
    }

    public function test_la_clave_vale_tanto_por_name_como_por_label(): void
    {
        // Las plantillas guardan `name` y `label`; el documento del contrato usa
        // el label como clave y el frontend ha usado ambos. Se admiten los dos
        // para no romper la asistencia ya capturada.
        $this->conPlantilla([
            ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => true],
        ]);

        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'extra_fields' => ['profesion' => 'Docente'],
        ]))->assertStatus(201);

        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'cedula' => '71000002',
            'extra_fields' => ['Profesión' => 'Ingeniera'],
        ]))->assertStatus(201);

        $this->assertDatabaseCount('meeting_attendees', 2);
    }

    public function test_una_reunion_sin_plantilla_no_exige_ni_restringe_campos(): void
    {
        // RF-5: sin plantilla no hay contra qué validar. El flujo público de
        // siempre —reuniones sin formulario configurado— no se toca.
        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'extra_fields' => ['lo_que_sea' => 'pasa'],
        ]))->assertStatus(201);

        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload(['cedula' => '71000002']))
            ->assertStatus(201);

        $this->assertDatabaseCount('meeting_attendees', 2);
    }

    public function test_una_plantilla_sin_campos_no_admite_extras(): void
    {
        // Una plantilla que no declara nada sí es una plantilla: cualquier clave
        // es un campo no declarado. Enviar `{}` o no enviar nada sigue valiendo.
        $this->conPlantilla([]);

        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'extra_fields' => ['lo_que_sea' => 'pasa'],
        ]))->assertStatus(422)->assertJsonValidationErrors(['extra_fields']);

        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'extra_fields' => [],
        ]))->assertStatus(201);

        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload(['cedula' => '71000002']))
            ->assertStatus(201);
    }

    public function test_cada_reunion_se_valida_contra_la_plantilla_de_su_tenant(): void
    {
        // Art. III: el ámbito lo fija la reunión del QR, no la plantilla que
        // exista en otra campaña. Lo obligatorio en el tenant A no aplica en B,
        // y un campo del A es «no declarado» en el B.
        $this->conPlantilla([
            ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => true],
        ]);

        $otro = Tenant::factory()->create();
        $reunionAjena = Meeting::factory()->forTenant($otro)->create(['qr_code' => 'QR-DE-OTRA-CAMPANNA']);
        $reunionAjena->update([
            'template_id' => $this->plantillaCon([
                ['name' => 'barrio_donde_vive', 'label' => 'Barrio', 'type' => 'text', 'required' => true],
            ], $otro)->id,
        ]);

        $this->postJson('/api/v1/meetings/check-in/QR-DE-OTRA-CAMPANNA', $this->payload([
            'extra_fields' => ['profesion' => 'Docente'],
        ]))->assertStatus(422)->assertJsonValidationErrors(['extra_fields']);

        $this->postJson('/api/v1/meetings/check-in/QR-DE-OTRA-CAMPANNA', $this->payload([
            'extra_fields' => ['barrio_donde_vive' => 'Centenario'],
        ]))->assertStatus(201);

        $this->assertDatabaseHas('meeting_attendees', [
            'tenant_id' => $otro->id,
            'cedula' => '71000001',
        ]);
    }

    public function test_la_validacion_no_rompe_la_deduplicacion_de_la_0022(): void
    {
        // Dos check-in válidos de la misma cédula siguen siendo una sola fila, y
        // el segundo puede corregir la respuesta del primero.
        $this->conPlantilla([
            [
                'name' => 'estrato',
                'label' => 'Estrato socioeconómico',
                'type' => 'radio',
                'required' => true,
                'options' => ['Estrato 1', 'Estrato 2'],
            ],
        ]);

        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'extra_fields' => ['estrato' => 'Estrato 1'],
        ]))->assertStatus(201);

        $this->postJson('/api/v1/meetings/check-in/QR-CAMPOS', $this->payload([
            'extra_fields' => ['estrato' => 'Estrato 2'],
        ]))->assertStatus(201);

        $this->assertDatabaseCount('meeting_attendees', 1);

        $asistente = MeetingAttendee::withoutGlobalScope(TenantScope::class)->firstOrFail();

        $this->assertSame(['estrato' => 'Estrato 2'], $asistente->extra_fields);
        $this->assertNotNull($asistente->voter_id);
    }

    // ==================================================================
    // Alta autenticada — POST /meetings/{meeting}/attendees
    // ==================================================================

    public function test_el_alta_autenticada_rechaza_un_campo_no_declarado(): void
    {
        $this->conPlantilla([
            ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => false],
        ]);

        $this->comoPlanificador()
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attendees", $this->payload([
                'extra_fields' => ['campo_inventado' => 'lo que sea'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['extra_fields']);

        $this->assertDatabaseCount('meeting_attendees', 0);
    }

    public function test_el_alta_autenticada_exige_los_campos_obligatorios(): void
    {
        $this->conPlantilla([
            ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => true],
        ]);

        $respuesta = $this->comoPlanificador()
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attendees", $this->payload());

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['extra_fields']);

        $this->assertContains(
            'El campo «Profesión» es obligatorio.',
            $respuesta->json('errors.extra_fields')
        );
    }

    public function test_el_alta_autenticada_rechaza_una_opcion_invalida(): void
    {
        $this->conPlantilla([
            [
                'name' => 'estrato',
                'label' => 'Estrato socioeconómico',
                'type' => 'select',
                'required' => true,
                'options' => ['Estrato 1', 'Estrato 2'],
            ],
        ]);

        $this->comoPlanificador()
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attendees", $this->payload([
                'extra_fields' => ['estrato' => 'Estrato 9'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['extra_fields']);
    }

    public function test_el_alta_autenticada_valida_se_registra(): void
    {
        $this->conPlantilla([
            [
                'name' => 'estrato',
                'label' => 'Estrato socioeconómico',
                'type' => 'select',
                'required' => true,
                'options' => ['Estrato 1', 'Estrato 2'],
            ],
        ]);

        $this->comoPlanificador()
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attendees", $this->payload([
                'extra_fields' => ['estrato' => 'Estrato 1'],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.extra_fields.estrato', 'Estrato 1');
    }

    public function test_el_alta_autenticada_sin_plantilla_no_exige_extras(): void
    {
        $this->comoPlanificador()
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attendees", $this->payload())
            ->assertStatus(201);
    }

    // ------------------------------------------------------------------

    /**
     * Un usuario del tenant de la reunión con permiso para darla de alta.
     */
    private function comoPlanificador(): static
    {
        [$usuario] = $this->createTenantWithUser(['create_meetings'], $this->tenant);

        return $this->actingAsTenantUser($usuario);
    }

    /**
     * @param  array<int, array<string, mixed>>  $campos
     */
    private function conPlantilla(array $campos): void
    {
        $this->meeting->update(['template_id' => $this->plantillaCon($campos)->id]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $campos
     */
    private function plantillaCon(array $campos, ?Tenant $tenant = null): MeetingTemplate
    {
        $tenant ??= $this->tenant;

        return MeetingTemplate::create([
            'tenant_id' => $tenant->id,
            'created_by' => User::factory()->forTenant($tenant)->create()->id,
            'name' => 'Caracterización socioeconómica',
            'description' => 'Formulario con preguntas extra',
            'fields' => $campos,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'cedula' => '71000001',
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
        ], $extra);
    }
}
