<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\E14ListaPreferente;
use App\Models\E14ListaResultado;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * «Mi candidato» cuando la campaña es a una corporación (Spec 0082).
 *
 * En alcaldía el candidato es un número del tarjetón y con eso basta. En concejo,
 * asamblea o senado no: es una persona **dentro de una lista**, y su identidad es
 * el par `(número de lista, número de preferencia)`. Un número suelto no sirve —
 * el cruce de la 0083 sumaría el 5 de todas las listas a la vez.
 *
 * Lo que **no** cambia respecto de la 0080: el nombre y el cargo siguen saliendo
 * del Tenant, la elección sigue siendo la del `tipo_cargo`, y borrar el número
 * sigue deshaciendo la ficha entera. Lo que se añade es la dimensión de la lista.
 */
class E14CandidatoCorporacionTest extends TestCase
{
    private const RUTA = '/api/v1/e14/candidato';

    private function operador(
        array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14],
        string $cargo = 'Concejo',
        string $nombre = 'ANA RUIZ',
        ?Tenant $tenant = null
    ): Tenant {
        $tenant ??= Tenant::factory()->create(['tipo_cargo' => $cargo, 'nombre' => $nombre]);
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    private function evento(Tenant $tenant, string $tipo = E14Acta::TIPO_CONCEJO): ElectoralEvent
    {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'nombre' => 'Concejo',
            'fecha' => '2027-10-31',
        ]);
    }

    /**
     * Un acta procesada con dos listas, que es de donde sale el catálogo.
     *
     * @param  array<int, array{0: int, 1: string|null, 2: array<int, int>}>  $listas
     */
    private function actaConListas(ElectoralEvent $evento, array $listas): E14Acta
    {
        $acta = E14Acta::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $evento->tenant_id,
            'electoral_event_id' => $evento->id,
            'tipo' => $evento->tipo,
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
            'estado' => E14Acta::ESTADO_PROCESADA,
        ]);

        foreach ($listas as [$numero, $nombre, $preferentes]) {
            $lista = E14ListaResultado::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $acta->tenant_id,
                'e14_acta_id' => $acta->id,
                'lista_numero' => $numero,
                'lista_nombre' => $nombre,
            ]);

            foreach ($preferentes as $preferente) {
                E14ListaPreferente::withoutGlobalScope(TenantScope::class)->create([
                    'tenant_id' => $acta->tenant_id,
                    'e14_acta_id' => $acta->id,
                    'e14_lista_resultado_id' => $lista->id,
                    'numero' => $preferente,
                    'votos' => 1,
                ]);
            }
        }

        return $acta;
    }

    private function tarjetonDeMuestra(ElectoralEvent $evento): void
    {
        $this->actaConListas($evento, [
            [11, 'PARTIDO CENTRO DEMOCRÁTICO', [1, 5, 9]],
            [1, 'PARTIDO LIBERAL COLOMBIANO', [3, 7]],
        ]);
    }

    // -------------------------------------------------------------- lectura

    public function test_el_get_dice_que_la_eleccion_es_de_corporacion(): void
    {
        $tenant = $this->operador();
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.nombre', 'ANA RUIZ')
            ->assertJsonPath('data.cargo', 'Concejo')
            ->assertJsonPath('meta.tipo_eleccion', 'concejo')
            // Es lo que le dice al frontend qué campos pedir (RF-4).
            ->assertJsonPath('meta.es_corporacion', true)
            ->assertJsonPath('data.lista_numero', null)
            ->assertJsonPath('data.numero', null)
            ->assertJsonPath('data.configurado', false);
    }

    public function test_el_get_trae_el_catalogo_de_listas_y_preferentes(): void
    {
        $tenant = $this->operador();
        $this->tarjetonDeMuestra($this->evento($tenant));

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.listas')
            // Ordenadas por número de lista, como el tarjetón.
            ->assertJsonPath('data.listas.0.lista_numero', 1)
            ->assertJsonPath('data.listas.0.lista_nombre', 'PARTIDO LIBERAL COLOMBIANO')
            ->assertJsonPath('data.listas.0.preferentes', [['numero' => 3], ['numero' => 7]])
            ->assertJsonPath('data.listas.1.lista_numero', 11)
            ->assertJsonCount(3, 'data.listas.1.preferentes')
            // El catálogo del uninominal no aplica aquí.
            ->assertJsonPath('data.candidatos', []);
    }

    public function test_sin_actas_el_catalogo_viene_vacio(): void
    {
        $tenant = $this->operador();
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.listas', [])
            ->assertJsonPath('data.eleccion.tiene_actas', false);
    }

    // ------------------------------------------------------------ escritura

    public function test_fijar_guarda_lista_y_preferente_con_el_nombre_del_tenant(): void
    {
        $tenant = $this->operador(nombre: 'ANA RUIZ');
        $evento = $this->evento($tenant);

        $this->putJson(self::RUTA, ['lista_numero' => 11, 'numero' => 5])
            ->assertStatus(200)
            ->assertJsonPath('data.lista_numero', 11)
            ->assertJsonPath('data.numero', 5)
            ->assertJsonPath('data.nombre', 'ANA RUIZ')
            ->assertJsonPath('data.configurado', true);

        $this->assertDatabaseHas('electoral_events', [
            'id' => $evento->id,
            'candidato_propio_lista_numero' => 11,
            'candidato_propio_numero' => 5,
            'candidato_propio_nombre' => 'ANA RUIZ',
        ]);
    }

    public function test_la_agrupacion_se_completa_con_el_nombre_de_la_lista(): void
    {
        $tenant = $this->operador();
        $this->tarjetonDeMuestra($this->evento($tenant));

        // La pantalla manda solo el par: el partido es el de la lista elegida.
        $this->putJson(self::RUTA, ['lista_numero' => 11, 'numero' => 5])
            ->assertStatus(200)
            ->assertJsonPath('data.agrupacion', 'PARTIDO CENTRO DEMOCRÁTICO');
    }

    public function test_la_agrupacion_que_mande_el_cliente_manda(): void
    {
        $tenant = $this->operador();
        $this->tarjetonDeMuestra($this->evento($tenant));

        $this->putJson(self::RUTA, [
            'lista_numero' => 11,
            'numero' => 5,
            'agrupacion' => 'COALICIÓN LOCAL',
        ])->assertStatus(200)->assertJsonPath('data.agrupacion', 'COALICIÓN LOCAL');
    }

    public function test_si_todavia_no_hay_eleccion_del_cargo_se_crea_al_fijar(): void
    {
        $tenant = $this->operador(cargo: 'Diputado');

        $this->putJson(self::RUTA, ['lista_numero' => 29, 'numero' => 4])
            ->assertStatus(200)
            ->assertJsonPath('data.eleccion.tipo', 'asamblea_departamental');

        $this->assertDatabaseHas('electoral_events', [
            'tenant_id' => $tenant->id,
            'tipo' => 'asamblea_departamental',
            'candidato_propio_lista_numero' => 29,
            'candidato_propio_numero' => 4,
        ]);
    }

    // --------------------------------------------------- los dos momentos

    public function test_sin_actas_el_par_se_acepta_a_ciegas(): void
    {
        $tenant = $this->operador();
        $this->evento($tenant);

        // El número de lista y el de preferencia se saben semanas antes de que
        // haya un acta que leer.
        $this->putJson(self::RUTA, ['lista_numero' => 5170, 'numero' => 19])
            ->assertStatus(200)
            ->assertJsonPath('data.lista_numero', 5170)
            ->assertJsonPath('data.numero', 19);
    }

    public function test_con_catalogo_una_lista_que_no_existe_es_422(): void
    {
        $tenant = $this->operador();
        $this->tarjetonDeMuestra($this->evento($tenant));

        $this->putJson(self::RUTA, ['lista_numero' => 24, 'numero' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lista_numero');

        $this->assertDatabaseHas('electoral_events', [
            'candidato_propio_lista_numero' => null,
            'candidato_propio_numero' => null,
        ]);
    }

    public function test_con_catalogo_un_preferente_que_no_esta_en_esa_lista_es_422(): void
    {
        $tenant = $this->operador();
        $this->tarjetonDeMuestra($this->evento($tenant));

        // El 3 existe, pero en la lista 1, no en la 11. El error va en `numero`
        // porque la lista sí es correcta: es el preferente el que falla.
        $this->putJson(self::RUTA, ['lista_numero' => 11, 'numero' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors('numero')
            ->assertJsonMissingValidationErrors('lista_numero');
    }

    public function test_con_catalogo_el_par_correcto_pasa(): void
    {
        $tenant = $this->operador();
        $this->tarjetonDeMuestra($this->evento($tenant));

        $this->putJson(self::RUTA, ['lista_numero' => 1, 'numero' => 7])
            ->assertStatus(200)
            ->assertJsonPath('data.lista_numero', 1)
            ->assertJsonPath('data.numero', 7);
    }

    public function test_un_acta_que_no_cuadra_no_abre_el_catalogo(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);

        $acta = $this->actaConListas($evento, [[11, 'CENTRO DEMOCRÁTICO', [1]]]);
        $acta->update(['estado' => E14Acta::ESTADO_INCONSISTENTE]);

        // Sin catálogo utilizable se vuelve al primer momento: a ciegas.
        $this->putJson(self::RUTA, ['lista_numero' => 99, 'numero' => 8])
            ->assertStatus(200)
            ->assertJsonPath('data.lista_numero', 99);
    }

    // ------------------------------------------------------------- deshacer

    public function test_quitar_el_numero_borra_la_ficha_entera(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);

        $this->putJson(self::RUTA, ['lista_numero' => 11, 'numero' => 5, 'agrupacion' => 'X'])
            ->assertStatus(200);

        $this->putJson(self::RUTA, ['numero' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.numero', null)
            ->assertJsonPath('data.lista_numero', null)
            ->assertJsonPath('data.agrupacion', null)
            ->assertJsonPath('data.configurado', false);

        $this->assertDatabaseHas('electoral_events', [
            'id' => $evento->id,
            'candidato_propio_numero' => null,
            'candidato_propio_lista_numero' => null,
            'candidato_propio_nombre' => null,
            'candidato_propio_agrupacion' => null,
        ]);
    }

    // ------------------------------------------------ lo que exige la forma

    public function test_en_corporacion_la_lista_es_obligatoria(): void
    {
        $tenant = $this->operador();
        $this->evento($tenant);

        // Un preferente suelto no identifica a nadie: el 5 existe en todas las
        // listas.
        $this->putJson(self::RUTA, ['numero' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lista_numero');
    }

    public function test_deshacer_no_exige_la_lista(): void
    {
        $tenant = $this->operador();
        $this->evento($tenant);

        $this->putJson(self::RUTA, ['numero' => null])->assertStatus(200);
    }

    public function test_un_cargo_que_no_mapea_sigue_avisando(): void
    {
        $tenant = $this->operador(cargo: 'Otro');
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('meta.cargo_mapeado', false)
            ->assertJsonPath('meta.es_corporacion', false);

        $this->putJson(self::RUTA, ['lista_numero' => 11, 'numero' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cargo');
    }

    // ---------------------------------------------------------- aislamiento

    public function test_el_catalogo_y_el_candidato_de_otra_campana_no_se_ven(): void
    {
        $ajeno = Tenant::factory()->create(['tipo_cargo' => 'Concejo', 'nombre' => 'CANDIDATA AJENA']);
        $eventoAjeno = $this->evento($ajeno);
        $this->actaConListas($eventoAjeno, [[99, 'LISTA AJENA', [7]]]);
        $eventoAjeno->update([
            'candidato_propio_lista_numero' => 99,
            'candidato_propio_numero' => 7,
        ]);

        $propio = $this->operador(nombre: 'ANA RUIZ');
        $this->evento($propio);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.nombre', 'ANA RUIZ')
            ->assertJsonPath('data.lista_numero', null)
            ->assertJsonPath('data.listas', []);

        // Y fijar aquí no toca lo del vecino.
        $this->putJson(self::RUTA, ['lista_numero' => 11, 'numero' => 5])->assertStatus(200);

        $this->assertDatabaseHas('electoral_events', [
            'id' => $eventoAjeno->id,
            'candidato_propio_lista_numero' => 99,
            'candidato_propio_numero' => 7,
        ]);
    }

    // ------------------------------------------------- el uninominal intacto

    public function test_un_tenant_de_alcaldia_sigue_configurando_solo_el_numero(): void
    {
        $tenant = $this->operador(cargo: 'Alcaldia', nombre: 'MIGUEL ALCALDE');
        $evento = $this->evento($tenant, E14Acta::TIPO_ALCALDIA);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('meta.es_corporacion', false)
            ->assertJsonPath('data.lista_numero', null)
            // El catálogo de corporación no aplica.
            ->assertJsonPath('data.listas', []);

        $this->putJson(self::RUTA, ['numero' => 7])
            ->assertStatus(200)
            ->assertJsonPath('data.numero', 7)
            ->assertJsonPath('data.lista_numero', null);

        $this->assertDatabaseHas('electoral_events', [
            'id' => $evento->id,
            'candidato_propio_numero' => 7,
            // Sigue en null: en uninominal el candidato no está dentro de
            // ninguna lista, y de eso depende que el cruce no cambie.
            'candidato_propio_lista_numero' => null,
        ]);
    }

    public function test_una_lista_mandada_a_un_uninominal_se_ignora(): void
    {
        $tenant = $this->operador(cargo: 'Alcaldia');
        $evento = $this->evento($tenant, E14Acta::TIPO_ALCALDIA);

        $this->putJson(self::RUTA, ['numero' => 7, 'lista_numero' => 11])
            ->assertStatus(200)
            ->assertJsonPath('data.lista_numero', null);

        $this->assertDatabaseHas('electoral_events', [
            'id' => $evento->id,
            'candidato_propio_lista_numero' => null,
        ]);
    }

    // ---------------------------------------------------------- permisos

    public function test_configurarlo_exige_manage_e14(): void
    {
        $tenant = $this->operador([Permissions::VIEW_E14]);
        $this->evento($tenant);

        $this->putJson(self::RUTA, ['lista_numero' => 11, 'numero' => 5])->assertStatus(403);
    }

    public function test_verlo_exige_view_e14(): void
    {
        $this->operador([Permissions::MANAGE_E14]);

        $this->getJson(self::RUTA)->assertStatus(403);
    }
}
