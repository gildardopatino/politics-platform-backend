<?php

namespace Tests\Feature\E14;

use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * «Mi candidato», único por tenant (Spec 0080).
 *
 * Una campaña es **un** candidato a **un** cargo, y el tenant ya lo sabe: tiene
 * `nombre` y `tipo_cargo`. Lo que la 0062 dejó —una ficha por elección, con el
 * nombre tecleado en cada una— permitía fijar candidatos en elecciones que no
 * son la de la campaña y volvía a pedir una identidad que ya estaba guardada.
 *
 * Aquí la identidad sale del tenant y lo único configurable es el número del
 * tarjetón, que aplica a la elección del cargo. La regla de los dos momentos de
 * la 0062 sigue intacta: sin actas el número se acepta a ciegas, y en cuanto hay
 * catálogo tiene que existir en él.
 */
class E14CandidatoDelTenantTest extends TestCase
{
    private const RUTA = '/api/v1/e14/candidato';

    private function operador(
        array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14],
        string $cargo = 'Alcaldia',
        string $nombre = 'MIGUEL ALCALDE',
        ?Tenant $tenant = null
    ): Tenant {
        $tenant ??= Tenant::factory()->create(['tipo_cargo' => $cargo, 'nombre' => $nombre]);
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    private function evento(Tenant $tenant, string $tipo = 'alcaldia', string $nombre = 'Alcaldía'): ElectoralEvent
    {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'nombre' => $nombre,
            'fecha' => '2027-10-31',
        ]);
    }

    /** Un acta que cuadra: 50 + 30 + 19 + 4 + 4 + 4 = 111. */
    private function cargarActa(string $tipo = 'alcaldia'): void
    {
        $this->postJson('/api/v1/e14/actas', [
            'tipo' => $tipo,
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
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
        ])->assertStatus(201);
    }

    // ------------------------------------------------------------- lectura

    public function test_la_identidad_sale_del_tenant_no_de_la_eleccion(): void
    {
        $tenant = $this->operador(cargo: 'Alcaldia', nombre: 'MIGUEL ALCALDE');
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            // Nombre y cargo no se teclean: ya están en la configuración de la
            // campaña.
            ->assertJsonPath('data.nombre', 'MIGUEL ALCALDE')
            ->assertJsonPath('data.cargo', 'Alcaldia')
            ->assertJsonPath('data.cargo_label', 'Alcaldía')
            ->assertJsonPath('data.numero', null)
            ->assertJsonPath('data.configurado', false)
            ->assertJsonPath('meta.cargo_mapeado', true)
            ->assertJsonPath('meta.tipo_eleccion', 'alcaldia')
            ->assertJsonPath('meta.aviso', null);
    }

    public function test_devuelve_la_eleccion_del_cargo_con_su_tarjeton(): void
    {
        $this->operador();
        $this->cargarActa();

        $evento = ElectoralEvent::first();

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.eleccion.id', $evento->id)
            ->assertJsonPath('data.eleccion.tipo', 'alcaldia')
            ->assertJsonPath('data.eleccion.tiene_actas', true)
            // El catálogo viaja con la ficha: es de donde se elige el número.
            ->assertJsonCount(3, 'data.candidatos')
            ->assertJsonPath('data.candidatos.1.nombre', 'JOHANA ARANDA');
    }

    public function test_sin_eleccion_del_cargo_todavia_no_hay_ficha_pero_si_identidad(): void
    {
        $tenant = $this->operador(cargo: 'Gobernacion');
        // Una elección de otro tipo no es la de esta campaña.
        $this->evento($tenant, 'alcaldia', 'Alcaldía');

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.eleccion', null)
            ->assertJsonPath('data.numero', null)
            ->assertJsonPath('data.candidatos', [])
            ->assertJsonPath('meta.cargo_mapeado', true)
            ->assertJsonPath('meta.tipo_eleccion', 'gobernacion');
    }

    public function test_no_lee_el_candidato_de_una_eleccion_que_no_es_la_del_cargo(): void
    {
        $tenant = $this->operador(cargo: 'Alcaldia');

        // Resto de la UI vieja: un candidato fijado en el concejo.
        $concejo = $this->evento($tenant, 'concejo', 'Concejo');
        $concejo->update(['candidato_propio_numero' => 9, 'candidato_propio_nombre' => 'OTRO']);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.numero', null)
            ->assertJsonPath('data.configurado', false);
    }

    // ---------------------------------------------------------- escritura

    public function test_fijar_el_numero_lo_escribe_en_la_eleccion_del_cargo_con_el_nombre_del_tenant(): void
    {
        $tenant = $this->operador(cargo: 'Alcaldia', nombre: 'MIGUEL ALCALDE');
        $evento = $this->evento($tenant);

        $this->putJson(self::RUTA, ['numero' => 7, 'agrupacion' => 'MOVIMIENTO INDEPENDIENTE'])
            ->assertStatus(200)
            ->assertJsonPath('data.numero', 7)
            ->assertJsonPath('data.nombre', 'MIGUEL ALCALDE')
            ->assertJsonPath('data.agrupacion', 'MOVIMIENTO INDEPENDIENTE')
            ->assertJsonPath('data.configurado', true)
            ->assertJsonPath('data.eleccion.id', $evento->id);

        // El nombre se copia desde el tenant para que los lectores del cruce
        // (0062/0076/0063) lo encuentren donde siempre, sin cambiar nada.
        $this->assertDatabaseHas('electoral_events', [
            'id' => $evento->id,
            'candidato_propio_numero' => 7,
            'candidato_propio_nombre' => 'MIGUEL ALCALDE',
            'candidato_propio_agrupacion' => 'MOVIMIENTO INDEPENDIENTE',
        ]);
    }

    public function test_si_todavia_no_hay_eleccion_del_cargo_se_crea_al_fijar(): void
    {
        // El cargo era `Diputado` hasta la 0082, que lo dejó pidiendo también la
        // lista —una asamblea es corporación—. Este archivo cubre el uninominal,
        // así que se prueba con uno; el find-or-create de corporación va en
        // `E14CandidatoCorporacionTest`, y que `Diputado` mapee a la asamblea lo
        // fija `EventoDelCargoTest`.
        $tenant = $this->operador(cargo: 'Gobernacion');

        $this->putJson(self::RUTA, ['numero' => 4])
            ->assertStatus(200)
            ->assertJsonPath('data.numero', 4)
            ->assertJsonPath('data.eleccion.tipo', 'gobernacion')
            ->assertJsonPath('data.eleccion.nombre', 'Gobernación');

        $this->assertDatabaseHas('electoral_events', [
            'tenant_id' => $tenant->id,
            'tipo' => 'gobernacion',
            'candidato_propio_numero' => 4,
        ]);
    }

    public function test_sin_actas_el_numero_se_acepta_a_ciegas(): void
    {
        $tenant = $this->operador();
        $this->evento($tenant);

        // El número del tarjetón se sabe semanas antes de la primera acta.
        $this->putJson(self::RUTA, ['numero' => 99])
            ->assertStatus(200)
            ->assertJsonPath('data.numero', 99);
    }

    public function test_con_actas_cargadas_el_numero_debe_estar_en_el_tarjeton(): void
    {
        $this->operador();
        $this->cargarActa();

        $this->putJson(self::RUTA, ['numero' => 9])
            ->assertStatus(422)
            ->assertJsonValidationErrors('numero');

        $this->assertDatabaseHas('electoral_events', [
            'id' => ElectoralEvent::first()->id,
            'candidato_propio_numero' => null,
        ]);
    }

    public function test_elegirlo_del_catalogo_completa_la_agrupacion_pero_no_el_nombre(): void
    {
        $tenant = $this->operador(nombre: 'MIGUEL ALCALDE');
        $this->cargarActa();

        // La pantalla manda solo el número. La agrupación se completa desde el
        // tarjetón; el nombre NO: la identidad es la del tenant, no la que
        // aparezca escrita en el acta.
        $this->putJson(self::RUTA, ['numero' => 2])
            ->assertStatus(200)
            ->assertJsonPath('data.numero', 2)
            ->assertJsonPath('data.nombre', 'MIGUEL ALCALDE');

        $this->assertDatabaseHas('electoral_events', [
            'tenant_id' => $tenant->id,
            'candidato_propio_numero' => 2,
            'candidato_propio_nombre' => 'MIGUEL ALCALDE',
        ]);
    }

    public function test_quitar_el_numero_borra_la_ficha_entera(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);

        $this->putJson(self::RUTA, ['numero' => 7, 'agrupacion' => 'MOVIMIENTO X'])->assertStatus(200);

        $this->putJson(self::RUTA, ['numero' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.numero', null)
            ->assertJsonPath('data.agrupacion', null)
            ->assertJsonPath('data.configurado', false);

        $this->assertDatabaseHas('electoral_events', [
            'id' => $evento->id,
            'candidato_propio_numero' => null,
            'candidato_propio_nombre' => null,
            'candidato_propio_agrupacion' => null,
        ]);
    }

    public function test_el_numero_es_obligatorio_en_la_peticion(): void
    {
        $tenant = $this->operador();
        $this->evento($tenant);

        // Sin `numero` no se sabe si se quiere fijar o borrar: 422 antes que
        // adivinar.
        $this->putJson(self::RUTA, ['agrupacion' => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('numero');
    }

    public function test_el_nombre_que_mande_el_cliente_se_ignora(): void
    {
        $tenant = $this->operador(nombre: 'MIGUEL ALCALDE');
        $this->evento($tenant);

        $this->putJson(self::RUTA, ['numero' => 7, 'nombre' => 'NOMBRE INVENTADO'])
            ->assertStatus(200)
            ->assertJsonPath('data.nombre', 'MIGUEL ALCALDE');

        $this->assertDatabaseMissing('electoral_events', [
            'candidato_propio_nombre' => 'NOMBRE INVENTADO',
        ]);
    }

    // ------------------------------------------------- el cargo que no mapea

    public function test_un_cargo_sin_eleccion_popular_avisa_en_vez_de_adivinar(): void
    {
        $tenant = $this->operador(cargo: 'Otro');
        $this->evento($tenant, 'alcaldia', 'Alcaldía');

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('meta.cargo_mapeado', false)
            ->assertJsonPath('meta.tipo_eleccion', null)
            ->assertJsonPath('data.eleccion', null)
            // El aviso es el punto: decir qué falta configurar, no fijar el
            // número en la elección equivocada.
            ->assertJsonPath('meta.aviso', fn ($aviso) => is_string($aviso) && $aviso !== '');
    }

    public function test_con_un_cargo_que_no_mapea_no_se_puede_fijar_el_numero(): void
    {
        $tenant = $this->operador(cargo: 'Otro');
        $evento = $this->evento($tenant, 'alcaldia', 'Alcaldía');

        $this->putJson(self::RUTA, ['numero' => 7])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cargo');

        // Ni lo escribió en la elección que había, ni inventó una nueva.
        $this->assertDatabaseHas('electoral_events', [
            'id' => $evento->id,
            'candidato_propio_numero' => null,
        ]);
        $this->assertSame(1, ElectoralEvent::withoutGlobalScope(TenantScope::class)->count());
    }

    // --------------------------------- no hay forma de fijar en otra elección

    public function test_ya_no_existe_la_ruta_por_eleccion(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant, 'concejo', 'Concejo');

        // La 0080 retira el `PUT /eventos/{id}/candidato-propio`: era la única
        // forma de poner candidato en una elección que no es la del cargo.
        $this->putJson("/api/v1/e14/eventos/{$evento->id}/candidato-propio", ['numero' => 7])
            ->assertStatus(404);
    }

    // ------------------------------------------------ lo que el cruce ve

    public function test_el_cruce_encuentra_el_candidato_fijado_por_aqui(): void
    {
        $this->operador();
        $this->cargarActa();

        $this->putJson(self::RUTA, ['numero' => 2])->assertStatus(200);

        // Los lectores del cruce no cambiaron: siguen leyendo el número del
        // evento, que es donde este endpoint lo escribe.
        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonPath('meta.candidato.numero', 2)
            ->assertJsonPath('meta.candidato.nombre', 'MIGUEL ALCALDE');
    }

    // ---------------------------------------------- permisos y aislamiento

    public function test_verlo_exige_view_e14(): void
    {
        $this->operador([Permissions::MANAGE_E14]);

        $this->getJson(self::RUTA)->assertStatus(403);
    }

    public function test_configurarlo_exige_manage_e14(): void
    {
        $tenant = $this->operador([Permissions::VIEW_E14]);
        $this->evento($tenant);

        $this->putJson(self::RUTA, ['numero' => 7])->assertStatus(403);
    }

    public function test_no_se_ve_ni_se_toca_el_candidato_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->create(['tipo_cargo' => 'Alcaldia', 'nombre' => 'CANDIDATA AJENA']);
        $eventoAjeno = $this->evento($ajeno);
        $eventoAjeno->update(['candidato_propio_numero' => 5, 'candidato_propio_nombre' => 'CANDIDATA AJENA']);

        $this->operador(nombre: 'MIGUEL ALCALDE');

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.nombre', 'MIGUEL ALCALDE')
            ->assertJsonPath('data.numero', null);

        $this->putJson(self::RUTA, ['numero' => 8])->assertStatus(200);

        // El candidato del vecino quedó exactamente como estaba.
        $this->assertDatabaseHas('electoral_events', [
            'id' => $eventoAjeno->id,
            'candidato_propio_numero' => 5,
        ]);
    }
}
