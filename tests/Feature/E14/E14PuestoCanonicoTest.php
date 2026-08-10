<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use App\Services\E14\PuestoResolver;
use App\Support\Permissions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El acta resuelve a un puesto del catálogo (Spec 0062 · Parte A).
 *
 * Es la llave del cruce: el votante ya tiene `voting_place_id`, y aquí el acta
 * gana el suyo resolviendo el `lugar` impreso contra `voting_places`. Lo que casa
 * por normalización se une solo; lo que no, se queda sin puesto y aparece en la
 * cobertura. Nunca se inventa un match.
 */
class E14PuestoCanonicoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('e14.disk'));
    }

    private function operador(?Tenant $tenant = null): Tenant
    {
        $tenant ??= Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser(
            [Permissions::VIEW_E14, Permissions::MANAGE_E14],
            $tenant
        );

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    private function pendiente(Tenant $tenant, string $semilla = 'a'): E14Acta
    {
        $evento = ElectoralEvent::withoutGlobalScope(TenantScope::class)->firstOrCreate([
            'tenant_id' => $tenant->id,
            'tipo' => 'alcaldia',
            'nombre' => 'Alcaldía',
        ]);

        $hash = hash('sha256', "{$tenant->id}-{$semilla}");

        Storage::disk(config('e14.disk'))->put("e14/{$tenant->id}/{$hash}.pdf", '%PDF-1.4');

        return E14Acta::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $evento->id,
            'tipo' => 'alcaldia',
            'archivo_nombre' => "acta-{$semilla}.pdf",
            'archivo_hash' => $hash,
            'archivo_path' => "e14/{$tenant->id}/{$hash}.pdf",
            'estado' => E14Acta::ESTADO_PENDIENTE,
        ]);
    }

    /** Lectura que cuadra: 50 + 30 + 19 + 4 + 4 + 4 = 111. */
    private function lectura(array $cambios = []): array
    {
        return array_replace([
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => 'COLEGIO SAN SIMON',
            'suma_declarada' => 111,
            'votos_urna' => 111,
            'votantes_e11' => 111,
            'votos_blanco' => 4,
            'votos_nulos' => 4,
            'votos_no_marcados' => 4,
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 50],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ], $cambios);
    }

    private function puestoDelCatalogo(array $cambios = []): VotingPlace
    {
        return VotingPlace::create(array_replace([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
        ], $cambios));
    }

    // ----------------------------------------------------- resolver el acta

    public function test_el_acta_resuelve_al_puesto_que_ya_esta_en_el_catalogo(): void
    {
        $tenant = $this->operador();
        // El votante se registró con el nombre acentuado; el acta trae otro.
        $lugar = $this->puestoDelCatalogo([
            'municipio_votacion' => 'Ibagué',
            'puesto_votacion' => 'Colegio San Simón',
        ]);
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura())
            ->assertStatus(200)
            ->assertJsonPath('data.voting_place_id', $lugar->id);

        // No se creó un segundo renglón para la misma escuela.
        $this->assertSame(1, VotingPlace::count());
    }

    public function test_un_lugar_que_no_esta_en_el_catalogo_entra_en_el(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        // El acta es el documento oficial del puesto: si no está en el catálogo,
        // lo que falta es el renglón.
        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura())
            ->assertStatus(200);

        $this->assertDatabaseHas('voting_places', [
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
        ]);

        $this->assertSame(
            VotingPlace::first()->id,
            E14Acta::withoutGlobalScope(TenantScope::class)->find($acta->id)->voting_place_id
        );
    }

    public function test_dos_actas_del_mismo_puesto_escrito_distinto_caen_en_el_mismo(): void
    {
        $tenant = $this->operador();
        $primera = $this->pendiente($tenant, 'a');
        $segunda = $this->pendiente($tenant, 'b');

        $this->postJson("/api/v1/e14/actas/{$primera->id}/resultado", $this->lectura());
        $this->postJson("/api/v1/e14/actas/{$segunda->id}/resultado", $this->lectura([
            'mesa' => '002',
            'municipio' => 'Ibagué',
            'lugar' => 'colegio  san simón',
        ]));

        $this->assertSame(1, VotingPlace::count());

        $actas = E14Acta::withoutGlobalScope(TenantScope::class)
            ->whereIn('id', [$primera->id, $segunda->id])
            ->pluck('voting_place_id')
            ->unique();

        $this->assertCount(1, $actas);
        $this->assertNotNull($actas->first());
    }

    public function test_sin_lugar_el_acta_se_queda_sin_puesto(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson(
            "/api/v1/e14/actas/{$acta->id}/resultado",
            array_replace($this->lectura(), ['lugar' => null])
        )->assertStatus(200)
            ->assertJsonPath('data.voting_place_id', null);

        $this->assertSame(0, VotingPlace::count());
    }

    public function test_sin_departamento_no_se_da_de_alta_un_puesto(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        // El departamento es parte de la clave natural del catálogo y no se
        // rellena con un placeholder: el acta queda sin puesto y se reporta.
        $this->postJson(
            "/api/v1/e14/actas/{$acta->id}/resultado",
            array_replace($this->lectura(), ['departamento' => null])
        )->assertStatus(200)
            ->assertJsonPath('data.voting_place_id', null);

        $this->assertSame(0, VotingPlace::count());
    }

    public function test_sin_departamento_el_acta_igual_resuelve_si_el_puesto_ya_existe(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $acta = $this->pendiente($tenant);

        // Lo común entre los dos lados es municipio + puesto; el departamento
        // solo hace falta para crear.
        $this->postJson(
            "/api/v1/e14/actas/{$acta->id}/resultado",
            array_replace($this->lectura(), ['departamento' => null])
        )->assertStatus(200)
            ->assertJsonPath('data.voting_place_id', $lugar->id);
    }

    public function test_un_acta_ilegible_conserva_el_puesto_que_si_se_leyo(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $acta = $this->pendiente($tenant);

        // El encabezado se lee casi siempre; son las casillas las que se tuercen.
        // Quien revise el papel necesita saber de qué puesto es.
        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura([
            'estado' => 'revision_manual',
            'observacion' => 'las casillas están tachadas',
        ]))->assertStatus(200)
            ->assertJsonPath('data.estado', 'revision_manual')
            ->assertJsonPath('data.voting_place_id', $lugar->id);
    }

    // ------------------------------------------------- corrección y respaldo

    public function test_corregir_el_nombre_del_puesto_mueve_el_acta_de_sitio(): void
    {
        $tenant = $this->operador();
        $bueno = $this->puestoDelCatalogo();
        $acta = $this->pendiente($tenant);

        // La visión leyó «COLEGIO SAM SIMON» y creó un renglón que no existe.
        $this->postJson(
            "/api/v1/e14/actas/{$acta->id}/resultado",
            $this->lectura(['lugar' => 'COLEGIO SAM SIMON'])
        )->assertStatus(200);

        $malo = VotingPlace::where('puesto_votacion', 'COLEGIO SAM SIMON')->firstOrFail();
        $this->assertNotSame($bueno->id, $malo->id);

        $this->putJson("/api/v1/e14/actas/{$acta->id}", ['lugar' => 'COLEGIO SAN SIMON'])
            ->assertStatus(200)
            ->assertJsonPath('data.voting_place_id', $bueno->id);
    }

    public function test_la_ingesta_directa_tambien_resuelve_el_puesto(): void
    {
        $this->operador();
        $lugar = $this->puestoDelCatalogo();

        $this->postJson('/api/v1/e14/actas', array_replace($this->lectura(), ['tipo' => 'alcaldia']))
            ->assertStatus(201)
            ->assertJsonPath('data.voting_place_id', $lugar->id);
    }

    public function test_conciliar_actas_resuelve_las_que_se_guardaron_sin_puesto(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        // Un acta anterior a esta spec: tiene ubicación pero no puesto canónico.
        E14Acta::withoutGlobalScope(TenantScope::class)->where('id', $acta->id)->update([
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => 'COLEGIO SAN SIMON',
            'estado' => E14Acta::ESTADO_PROCESADA,
            'voting_place_id' => null,
        ]);

        $lugar = $this->puestoDelCatalogo();

        $this->assertSame(1, app(PuestoResolver::class)->conciliarActas());

        $this->assertSame(
            $lugar->id,
            E14Acta::withoutGlobalScope(TenantScope::class)->find($acta->id)->voting_place_id
        );

        // Idempotente: la segunda pasada no tiene nada que resolver.
        $this->assertSame(0, app(PuestoResolver::class)->conciliarActas());
    }
}
