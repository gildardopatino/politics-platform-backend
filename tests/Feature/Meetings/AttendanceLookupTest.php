<?php

namespace Tests\Feature\Meetings;

use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Tenant;
use App\Models\Voter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El autocompletado del formulario del QR mira también a los votantes
 * (Spec 0022, RF-4).
 *
 * La ruta es la que creó la 0026 —`GET /meetings/public/{qr}/verify-document`—
 * y su política no cambia: el QR fija el tenant y la respuesta sigue sin
 * dirección ni puesto de votación. Lo que cambia es que ahora `voters`, que es
 * la base de la propia campaña, se consulta **antes** que las fuentes externas.
 */
class AttendanceLookupTest extends TestCase
{
    private Tenant $tenant;

    private mixed $recursoEnLinea;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->recursoEnLinea = Http::response('', 500);
        Http::fake(['*pisami*' => fn () => $this->recursoEnLinea]);

        $this->tenant = Tenant::factory()->create();
        Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-LOOKUP']);
    }

    public function test_encuentra_a_un_votante_de_la_campania(): void
    {
        Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001',
            'nombres' => 'Ana María',
            'apellidos' => 'Restrepo Gómez',
            'telefono' => '3001112233',
            'email' => 'ana@ejemplo.test',
        ]);

        $this->lookup('71000001')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('source', 'voters')
            ->assertJsonPath('data.nombres', 'Ana María')
            ->assertJsonPath('data.apellidos', 'Restrepo Gómez')
            ->assertJsonPath('data.telefono', '3001112233')
            ->assertJsonPath('data.email', 'ana@ejemplo.test');
    }

    public function test_el_votante_manda_sobre_las_fuentes_externas(): void
    {
        // La base de la campaña es la que alguien mantiene; PISAMI es un
        // registro externo que puede estar desactualizado.
        Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001',
            'nombres' => 'Ana María',
            'telefono' => '3001112233',
        ]);

        $this->recursoEnLinea = Http::response($this->respuestaPisami([
            'PRIMER_NOMBRE' => 'ANA',
            'TEL_MOVIL_NOTIFICACION' => '3000000000',
        ]));

        $this->lookup('71000001')
            ->assertStatus(200)
            ->assertJsonPath('source', 'voters')
            ->assertJsonPath('data.telefono', '3001112233');
    }

    public function test_la_cedula_se_normaliza_tambien_en_el_lookup(): void
    {
        Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001',
            'nombres' => 'Ana María',
        ]);

        $this->lookup('71.000.001')
            ->assertStatus(200)
            ->assertJsonPath('source', 'voters')
            ->assertJsonPath('data.nombres', 'Ana María');
    }

    public function test_sin_votante_sigue_cayendo_en_las_otras_fuentes(): void
    {
        Lead::create([
            'tenant_id' => $this->tenant->id,
            'cedula' => '71000001',
            'nombre1' => 'Ana',
            'apellido1' => 'Restrepo',
            'telefono' => '3001112233',
        ]);

        $this->lookup('71000001')
            ->assertStatus(200)
            ->assertJsonPath('source', 'leads')
            ->assertJsonPath('data.nombres', 'Ana');
    }

    public function test_la_fecha_de_nacimiento_del_lead_llega_al_formulario(): void
    {
        // El formulario del QR la pide y la persona la teclea igual: devolverla
        // no le ahorra un dato a nadie, le ahorra un campo a quien se registra.
        Lead::create([
            'tenant_id' => $this->tenant->id,
            'cedula' => '71000001',
            'nombre1' => 'Ana',
            'apellido1' => 'Restrepo',
            'fecha_nacimiento' => '1985-03-12',
        ]);

        $this->lookup('71000001')
            ->assertStatus(200)
            ->assertJsonPath('source', 'leads')
            ->assertJsonPath('data.fecha_nacimiento', '1985-03-12');
    }

    public function test_una_fuente_sin_fecha_de_nacimiento_la_devuelve_nula(): void
    {
        // `voters` y PISAMI no la traen: la clave existe y vale null, para que el
        // formulario no tenga que adivinar si falta el campo o el dato.
        Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001',
            'nombres' => 'Ana María',
        ]);

        $this->lookup('71000001')
            ->assertStatus(200)
            ->assertJsonPath('source', 'voters')
            ->assertJsonPath('data.fecha_nacimiento', null);
    }

    // ------------------------------------------------------------------
    // La política de la 0026 sigue en pie
    // ------------------------------------------------------------------

    public function test_no_devuelve_direccion_ni_puesto_de_votacion(): void
    {
        Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001',
            'nombres' => 'Ana María',
            'direccion' => 'Calle 50 #45-30',
            'puesto_votacion' => 'IE El Centro',
            'mesa_votacion' => '012',
        ]);

        $data = $this->lookup('71000001')->assertStatus(200)->json('data');

        // La lista blanca creció en **un** campo (`fecha_nacimiento`) y en nada
        // más: el recorte de la 0026 no se reabre.
        $this->assertSame(
            ['nombres', 'apellidos', 'telefono', 'email', 'fecha_nacimiento'],
            array_keys($data)
        );
    }

    public function test_la_fuente_mas_rica_tampoco_filtra_lo_que_la_0026_recorto(): void
    {
        // El lead es la fuente con más campos —dirección, puesto, mesa, zona y
        // coordenadas—: si el recorte se rompiera, se rompería por aquí.
        Lead::create([
            'tenant_id' => $this->tenant->id,
            'cedula' => '71000001',
            'nombre1' => 'Ana',
            'apellido1' => 'Restrepo',
            'fecha_nacimiento' => '1985-03-12',
            'telefono' => '3001112233',
            'direccion' => 'Calle 50 #45-30',
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'IE El Centro',
            'zona_votacion' => '01',
            'mesa_votacion' => '012',
            'direccion_votacion' => 'CARRERA 5 # 60-10',
            'latitud' => 4.4389,
            'longitud' => -75.2322,
        ]);

        $data = $this->lookup('71000001')->assertStatus(200)->json('data');

        $this->assertSame(
            ['nombres', 'apellidos', 'telefono', 'email', 'fecha_nacimiento'],
            array_keys($data)
        );

        foreach ([
            'direccion', 'departamento_votacion', 'municipio_votacion', 'puesto_votacion',
            'zona_votacion', 'mesa_votacion', 'direccion_votacion', 'latitud', 'longitud',
        ] as $recortado) {
            $this->assertArrayNotHasKey($recortado, $data);
        }
    }

    public function test_no_alcanza_a_los_votantes_de_otra_campania(): void
    {
        $otro = Tenant::factory()->create();
        Voter::factory()->forTenant($otro)->create([
            'cedula' => '72000009',
            'nombres' => 'Beatriz',
            'telefono' => '3005554433',
        ]);

        $this->lookup('72000009')->assertStatus(404);
    }

    public function test_sin_qr_valido_no_hay_consulta(): void
    {
        Voter::factory()->forTenant($this->tenant)->create(['cedula' => '71000001']);

        $this->getJson('/api/v1/meetings/public/QR-QUE-NO-EXISTE/verify-document?cedula=71000001')
            ->assertStatus(404);
    }

    public function test_la_ruta_autenticada_tambien_ve_a_los_votantes(): void
    {
        Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001',
            'nombres' => 'Ana María',
            'direccion' => 'Calle 50 #45-30',
        ]);

        [$user, $token] = $this->createTenantWithUser(['view_voters'], $this->tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/verify-document?cedula=71000001')
            ->assertStatus(200)
            ->assertJsonPath('source', 'voters')
            ->assertJsonPath('data.nombres', 'Ana María')
            // La ruta interna sí conserva el payload completo.
            ->assertJsonPath('data.direccion', 'Calle 50 #45-30');
    }

    // ------------------------------------------------------------------

    private function lookup(string $cedula, string $qr = 'QR-LOOKUP')
    {
        return $this->getJson("/api/v1/meetings/public/{$qr}/verify-document?cedula=".urlencode($cedula));
    }

    private function respuestaPisami(array $campos): string
    {
        $lineas = ['<script>'];
        foreach ($campos as $campo => $valor) {
            $lineas[] = "parent.document.f_pqr.{$campo}.value=\"{$valor}\";";
        }
        $lineas[] = '</script>';

        return implode("\n", $lineas);
    }
}
