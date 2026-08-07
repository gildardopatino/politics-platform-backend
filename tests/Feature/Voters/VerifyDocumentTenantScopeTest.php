<?php

namespace Tests\Feature\Voters;

use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fuga de PII cross-tenant en el verificador de documento (Spec 0026).
 *
 * `GET /verify-document?cedula=` era **público** y estaba fuera del grupo
 * `tenant`, así que no había `current_tenant_id` y `TenantScope` no filtraba:
 * cualquiera, sin sesión y sabiendo solo una cédula, obtenía nombre, teléfono,
 * correo, dirección y puesto de votación de un lead de **cualquier tenant**
 * (Artículo VII de la constitución).
 *
 * Ahora hay dos caminos, y ninguno permite eso:
 *
 * | Ruta | Quién | Ámbito | Devuelve |
 * | --- | --- | --- | --- |
 * | `GET /meetings/public/{qr}/verify-document` | cualquiera con el QR | tenant de la reunión | nombres, apellidos, teléfono, correo |
 * | `GET /verify-document` | usuario con `view_voters` | su tenant | payload completo |
 */
class VerifyDocumentTenantScopeTest extends TestCase
{
    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        // PISAMI es una API externa: nunca se llama de verdad desde la suite.
        Http::preventStrayRequests();
        Http::fake(['*pisami*' => Http::response('', 500)]);

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();

        Meeting::factory()->forTenant($this->tenantA)->create(['qr_code' => 'QR-TENANT-A']);
    }

    // ------------------------------------------------------------------
    // La fuga
    // ------------------------------------------------------------------

    public function test_el_qr_de_un_tenant_no_revela_leads_de_otro(): void
    {
        // La prueba de la fuga: cédula que solo existe en el tenant B,
        // consultada con el QR del tenant A.
        $this->crearLead($this->tenantB, '72000009');

        $this->getJson('/api/v1/meetings/public/QR-TENANT-A/verify-document?cedula=72000009')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_el_qr_encuentra_los_leads_de_su_propio_tenant(): void
    {
        $this->crearLead($this->tenantA, '71000001');

        $this->getJson('/api/v1/meetings/public/QR-TENANT-A/verify-document?cedula=71000001')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('source', 'leads')
            ->assertJsonPath('data.nombres', 'Ana')
            ->assertJsonPath('data.apellidos', 'Restrepo')
            ->assertJsonPath('data.telefono', '3001112233')
            ->assertJsonPath('data.email', 'ana@ejemplo.test');
    }

    public function test_la_respuesta_publica_no_lleva_direccion_ni_puesto_de_votacion(): void
    {
        // El formulario del QR solo necesita el nombre y el contacto. La
        // dirección y el puesto de votación son PII que no pinta ahí.
        $this->crearLead($this->tenantA, '71000001');

        $data = $this->getJson('/api/v1/meetings/public/QR-TENANT-A/verify-document?cedula=71000001')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(['nombres', 'apellidos', 'telefono', 'email'], array_keys($data));

        foreach ([
            'direccion', 'barrio', 'puesto_votacion', 'mesa_votacion',
            'zona_votacion', 'direccion_votacion', 'departamento_votacion',
            'municipio_votacion', 'fecha_nacimiento', 'latitud', 'longitud',
            'cedula', 'nombre_completo', 'locality_name',
        ] as $campo) {
            $this->assertArrayNotHasKey($campo, $data);
        }
    }

    public function test_un_qr_inexistente_no_consulta_nada(): void
    {
        $this->crearLead($this->tenantA, '71000001');

        $this->getJson('/api/v1/meetings/public/QR-QUE-NO-EXISTE/verify-document?cedula=71000001')
            ->assertStatus(404);
    }

    public function test_la_cedula_es_obligatoria(): void
    {
        $this->getJson('/api/v1/meetings/public/QR-TENANT-A/verify-document')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cedula']);
    }

    // ------------------------------------------------------------------
    // La ruta sin QR deja de ser pública
    // ------------------------------------------------------------------

    public function test_verify_document_sin_qr_ya_no_es_publico(): void
    {
        $this->crearLead($this->tenantB, '72000009');

        $this->getJson('/api/v1/verify-document?cedula=72000009')->assertStatus(401);
    }

    public function test_verify_document_autenticado_exige_view_voters(): void
    {
        [$user, $token] = $this->createTenantWithUser([], $this->tenantA);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/verify-document?cedula=71000001')
            ->assertStatus(403);
    }

    public function test_verify_document_autenticado_solo_ve_su_tenant(): void
    {
        $this->crearLead($this->tenantB, '72000009');

        [$user, $token] = $this->createTenantWithUser(['view_voters'], $this->tenantA);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/verify-document?cedula=72000009')
            ->assertStatus(404);
    }

    public function test_verify_document_autenticado_conserva_el_payload_completo(): void
    {
        // La pantalla del call center sí captura dirección y puesto de votación,
        // y quien la usa está autenticado y acotado a su tenant.
        $this->crearLead($this->tenantA, '71000001');

        [$user, $token] = $this->createTenantWithUser(['view_voters'], $this->tenantA);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/verify-document?cedula=71000001')
            ->assertStatus(200)
            ->assertJsonPath('data.nombres', 'Ana')
            ->assertJsonPath('data.direccion', 'Calle 50 #45-30')
            ->assertJsonPath('data.puesto_votacion', 'IE El Centro');
    }

    // ------------------------------------------------------------------
    // Límite de peticiones
    // ------------------------------------------------------------------

    public function test_la_ruta_publica_tiene_limite_de_peticiones(): void
    {
        // Sin límite, el endpoint es un oráculo de cédulas: se puede barrer el
        // espacio de documentos preguntando una por una.
        $this->crearLead($this->tenantA, '71000001');

        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/v1/meetings/public/QR-TENANT-A/verify-document?cedula=71000001')
                ->assertStatus(200);
        }

        $this->getJson('/api/v1/meetings/public/QR-TENANT-A/verify-document?cedula=71000001')
            ->assertStatus(429);
    }

    // ------------------------------------------------------------------

    private function crearLead(Tenant $tenant, string $cedula): Lead
    {
        return Lead::create([
            'tenant_id' => $tenant->id,
            'cedula' => $cedula,
            'nombre1' => 'Ana',
            'apellido1' => 'Restrepo',
            'telefono' => '3001112233',
            'email' => 'ana@ejemplo.test',
            'direccion' => 'Calle 50 #45-30',
            'puesto_votacion' => 'IE El Centro',
            'mesa_votacion' => '012',
        ]);
    }
}
