<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\E14Candidate;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Un tenant, una elección (Spec 0093 · capa 1).
 *
 * El producto se vende por uso: la campaña de un alcalde escruta alcaldía y nada
 * más. Hasta la 0092 media app ofrecía elegir el tipo de elección —la carga, el
 * cruce, el consolidado, las estadísticas—, y ese selector era, de hecho, la
 * forma de mirar la elección de al lado: bastaba con armar la petición a mano.
 *
 * Lo que se prueba aquí es que el tipo **sale del tenant** y de ningún otro
 * sitio: el `?tipo=` de la URL se ignora —no se rechaza con un error, que sería
 * una invitación a seguir probando— y la respuesta es siempre la de la campaña.
 */
class E14EleccionDelTenantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('e14.disk'));
    }

    /**
     * @param  array<int, string>  $permisos
     */
    private function operador(?Tenant $tenant = null, array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14]): Tenant
    {
        $tenant ??= Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    /**
     * La elección, ya con candidato propio: sin él el cruce y la proyección
     * responden «configúralo» en vez de calcular (Spec 0062).
     */
    private function evento(Tenant $tenant, string $tipo): ElectoralEvent
    {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'tipo' => $tipo,
                'nombre' => E14Acta::nombreDe($tipo),
            ],
            [
                'candidato_propio_numero' => 1,
                'candidato_propio_nombre' => 'JORGE BOLIVAR',
            ],
        );
    }

    /**
     * Un acta procesada de la mesa que se diga, con un solo candidato.
     */
    private function acta(Tenant $tenant, string $tipo, string $mesa, int $votos): E14Acta
    {
        $evento = $this->evento($tenant, $tipo);

        $acta = E14Acta::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $evento->id,
            'tipo' => $tipo,
            'zona' => '01',
            'puesto' => '01',
            'mesa' => $mesa,
            'archivo_hash' => hash('sha256', "{$tenant->id}-{$tipo}-{$mesa}"),
            'estado' => E14Acta::ESTADO_PROCESADA,
            'suma_declarada' => $votos,
            'suma_calculada' => $votos,
            'votos_urna' => $votos,
            'votantes_e11' => $votos,
        ]);

        // El consolidado agrupa por candidato del catálogo, no por el renglón
        // del acta: sin la ficha no habría a quién sumarle los votos.
        $candidato = E14Candidate::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            ['electoral_event_id' => $evento->id, 'cargo' => $tipo, 'numero' => 1],
            ['tenant_id' => $tenant->id, 'nombre' => 'JORGE BOLIVAR'],
        );

        $acta->resultados()->create([
            'tenant_id' => $tenant->id,
            'e14_candidate_id' => $candidato->id,
            'numero' => 1,
            'nombre' => 'JORGE BOLIVAR',
            'votos' => $votos,
        ]);

        return $acta;
    }

    private function pdf(string $nombre = 'mesa001.pdf', string $contenido = 'ACTA-001'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $nombre,
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%{$contenido}\n%%EOF"
        );
    }

    // ----------------------------------------------------------- inmutable

    public function test_editar_la_campana_no_le_cambia_la_eleccion(): void
    {
        $this->actingAsSuperAdmin();

        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']);

        // Todo el escrutinio cuelga del cargo: las actas ya cargadas, la
        // elección donde vive «mi candidato», el cruce. Cambiarlo dejaría a la
        // campaña rechazando sus propias actas por «ser de otra elección».
        $this->putJson("/api/v1/tenants/{$tenant->slug}", [
            'nombre' => 'MIGUEL ALCALDE',
            'tipo_cargo' => 'Gobernacion',
        ])->assertStatus(200);

        $tenant->refresh();

        $this->assertSame('Alcaldia', $tenant->tipo_cargo);
        $this->assertSame('alcaldia', $tenant->tipoEleccion());
        // Y lo demás del PUT sí surtió efecto: no se rechaza la petición
        // entera, solo se ignora el campo.
        $this->assertSame('MIGUEL ALCALDE', $tenant->nombre);
    }

    // --------------------------------------- los endpoints ignoran el ?tipo

    public function test_el_listado_solo_trae_las_actas_de_la_eleccion_de_la_campana(): void
    {
        $tenant = $this->operador();
        $this->acta($tenant, 'alcaldia', '001', 10);
        // Un acta de otra elección que entró por la ingesta directa: desde esta
        // campaña no se puede mirar, aunque se pida por su nombre.
        $this->acta($tenant, 'gobernacion', '002', 20);

        $this->getJson('/api/v1/e14/actas?tipo=gobernacion')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipo', 'alcaldia')
            ->assertJsonPath('data.0.mesa', '001')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_el_consolidado_es_el_de_la_eleccion_de_la_campana(): void
    {
        $tenant = $this->operador();
        $this->acta($tenant, 'alcaldia', '001', 10);
        $this->acta($tenant, 'gobernacion', '002', 20);

        $this->getJson('/api/v1/e14/consolidado?tipo=gobernacion')
            ->assertStatus(200)
            ->assertJsonPath('data.0.votos', 10)
            ->assertJsonPath('meta.total_candidatos', 10)
            ->assertJsonPath('meta.actas.procesada', 1);
    }

    public function test_las_estadisticas_son_las_de_la_eleccion_de_la_campana(): void
    {
        $tenant = $this->operador();
        $this->acta($tenant, 'alcaldia', '001', 10);
        $this->acta($tenant, 'gobernacion', '002', 20);

        // En la 0092 `tipo` era obligatorio y mandaba. Ahora se acepta y no
        // cambia nada: sigue siendo la elección de la campaña.
        $this->getJson('/api/v1/e14/estadisticas?tipo=gobernacion')
            ->assertStatus(200)
            ->assertJsonPath('meta.tipo', 'alcaldia');
    }

    public function test_las_estadisticas_ya_no_exigen_que_se_diga_la_eleccion(): void
    {
        $tenant = $this->operador();
        $this->acta($tenant, 'alcaldia', '001', 10);

        $this->getJson('/api/v1/e14/estadisticas')
            ->assertStatus(200)
            ->assertJsonPath('meta.tipo', 'alcaldia');
    }

    public function test_el_cruce_y_la_proyeccion_abren_sobre_la_eleccion_de_la_campana(): void
    {
        $tenant = $this->operador();
        $this->acta($tenant, 'alcaldia', '001', 10);

        // Más reciente que la de alcaldía: hasta la 0093 estos dos abrían sobre
        // «la última elección del tenant», así que esta les habría ganado.
        $gobernacion = $this->evento($tenant, 'gobernacion');
        $gobernacion->forceFill(['fecha' => now()->addYear()])->save();

        $alcaldia = $this->evento($tenant, 'alcaldia');

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonPath('meta.electoral_event_id', $alcaldia->id);

        $this->getJson('/api/v1/e14/proyeccion')
            ->assertStatus(200)
            ->assertJsonPath('meta.electoral_event_id', $alcaldia->id);
    }

    public function test_pedir_por_id_una_eleccion_ajena_al_cargo_no_la_sirve(): void
    {
        $tenant = $this->operador();
        $this->acta($tenant, 'alcaldia', '001', 10);
        $gobernacion = $this->evento($tenant, 'gobernacion');

        // Es del mismo tenant, así que existe; lo que no es, es su elección.
        $this->getJson('/api/v1/e14/cruce?event='.$gobernacion->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');

        $this->getJson('/api/v1/e14/estadisticas?event='.$gobernacion->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');
    }

    // --------------------------------------------------------------- carga

    public function test_la_carga_ya_no_pide_el_tipo_y_usa_el_de_la_campana(): void
    {
        $this->operador(Tenant::factory()->corporacion()->create());

        $this->postJson('/api/v1/e14/actas/upload', ['archivo' => $this->pdf()])
            ->assertStatus(201)
            ->assertJsonPath('data.tipo', 'concejo')
            ->assertJsonPath('data.estado', 'pendiente');
    }

    public function test_la_carga_ignora_el_tipo_que_mande_el_cliente(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'senado',
            'archivo' => $this->pdf(),
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.tipo', 'alcaldia');
    }

    // -------------------------------------------- la campaña que no escruta

    public function test_una_campana_sin_eleccion_no_puede_cargar_actas(): void
    {
        $this->operador(Tenant::factory()->create(['tipo_cargo' => 'Otro']));

        $this->postJson('/api/v1/e14/actas/upload', ['archivo' => $this->pdf()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cargo');

        $this->assertSame(0, E14Acta::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_una_campana_sin_eleccion_lo_dice_en_vez_de_reventar(): void
    {
        $this->operador(Tenant::factory()->create(['tipo_cargo' => 'Otro']));

        $rutas = [
            '/api/v1/e14/consolidado',
            '/api/v1/e14/cruce',
            '/api/v1/e14/estadisticas',
            '/api/v1/e14/proyeccion',
        ];

        foreach ($rutas as $ruta) {
            $this->getJson($ruta)
                ->assertStatus(422)
                ->assertJsonValidationErrors('cargo');
        }

        // El listado sí responde: una lista vacía es la respuesta honesta a
        // «enséñame tus actas» cuando no hay escrutinio que enseñar.
        $this->getJson('/api/v1/e14/actas')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    // ---------------------------------------------------------- aislamiento

    public function test_una_campana_no_ve_el_escrutinio_de_otra_pidiendo_su_tipo(): void
    {
        $ajena = Tenant::factory()->create(['tipo_cargo' => 'Gobernacion']);
        $this->acta($ajena, 'gobernacion', '001', 99);

        $this->operador(Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']));

        // Ni con el tipo de la otra…
        $this->getJson('/api/v1/e14/actas?tipo=gobernacion')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/e14/consolidado?tipo=gobernacion')
            ->assertStatus(200)
            ->assertJsonPath('meta.total_candidatos', 0);

        // …ni con el id de su elección: desde aquí, sencillamente no existe.
        $ajeno = ElectoralEvent::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $ajena->id)
            ->firstOrFail();

        $this->getJson('/api/v1/e14/cruce?event='.$ajeno->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');
    }
}
