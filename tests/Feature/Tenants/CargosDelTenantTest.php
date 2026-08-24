<?php

namespace Tests\Feature\Tenants;

use App\Http\Requests\Api\V1\Tenant\StoreTenantRequest;
use App\Http\Requests\Api\V1\Tenant\UpdateTenantRequest;
use App\Models\Tenant;
use App\Services\E14\EventoResolver;
use Illuminate\Routing\Route as RutaDeLaravel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Los cargos que una campaña puede tener (Spec 0084).
 *
 * Dos cosas se prueban aquí, y la segunda es la que duele:
 *
 * 1. **Cámara fuera.** `Representante` deja de ser elegible: el sistema no tiene
 *    actas de Cámara —no hay tipo de acta para ella en `E14Acta::TIPOS`—, así que
 *    ofrecer el cargo prometía un escrutinio y un cruce que no existen. Vuelve
 *    cuando exista el lector (extensión de la 0067).
 *
 * 2. **Una sola lista de cargos.** La 0080 destapó que el FormRequest aceptaba
 *    `Representante` y la columna no: una campaña de Cámara pasaba la validación
 *    y reventaba contra el CHECK del enum al insertar. Un formulario que acepta
 *    lo que la base rechaza es peor que uno estricto, porque el error aparece
 *    lejos de donde está la causa. Por eso la prueba compara **las tres**
 *    fuentes —columna, constante y reglas— y falla si alguien mueve una sola.
 */
class CargosDelTenantTest extends TestCase
{
    /**
     * Los valores que el `in:` de una regla acepta.
     *
     * @param  string|array<int, mixed>  $regla
     * @return array<int, string>
     */
    private function valoresDeLaRegla(string|array $regla): array
    {
        foreach (is_array($regla) ? $regla : explode('|', $regla) as $parte) {
            $texto = (string) $parte;

            if (str_starts_with($texto, 'in:')) {
                // `Rule::in()` entrecomilla cada valor al volverse texto.
                return array_map(
                    fn (string $valor) => trim(trim($valor), '"\''),
                    explode(',', substr($texto, 3))
                );
            }
        }

        $this->fail('La regla de tipo_cargo no lista valores con «in:».');
    }

    /**
     * Los valores del enum de la columna, leídos de la base.
     *
     * En SQLite —donde corre la suite— un `enum()` se materializa como un
     * `varchar` con un CHECK, así que la lista vive en el DDL de la tabla. Se lee
     * de ahí y no de una constante duplicada: si se leyera de la constante, la
     * prueba se estaría comparando consigo misma.
     *
     * @return array<int, string>
     */
    private function cargosDeLaColumna(): array
    {
        $ddl = (string) DB::table('sqlite_master')
            ->where('type', 'table')
            ->where('name', 'tenants')
            ->value('sql');

        $this->assertNotSame('', $ddl, 'No se pudo leer la definición de «tenants».');

        preg_match('/"tipo_cargo".*?check\s*\("tipo_cargo"\s+in\s*\((.*?)\)\)/is', $ddl, $coincide);

        $this->assertNotEmpty($coincide, 'La columna tipo_cargo no tiene lista de valores.');

        return array_map(
            fn (string $valor) => trim(trim($valor), "'"),
            explode(',', $coincide[1])
        );
    }

    /**
     * Las reglas del request de edición, que necesita su campaña en la ruta.
     *
     * `UpdateTenantRequest::rules()` lee `$this->route('tenant')` para excluirse a
     * sí misma del `unique`, así que instanciarlo pelado revienta. Se le arma la
     * ruta como se la armaría el router.
     *
     * @return array<string, mixed>
     */
    private function reglasDeEdicion(): array
    {
        $tenant = Tenant::factory()->create();

        $peticion = UpdateTenantRequest::create("/api/v1/tenants/{$tenant->slug}", 'PUT');
        $ruta = new RutaDeLaravel(['PUT'], 'api/v1/tenants/{tenant}', []);
        $ruta->bind($peticion);
        $ruta->setParameter('tenant', $tenant);
        $peticion->setRouteResolver(fn () => $ruta);

        return $peticion->rules();
    }

    // ------------------------------------------------- las tres fuentes

    public function test_la_columna_y_la_constante_dicen_los_mismos_cargos(): void
    {
        $this->assertEqualsCanonicalizing(
            Tenant::TIPOS_CARGO,
            $this->cargosDeLaColumna(),
            'El enum de la columna y `Tenant::TIPOS_CARGO` divergieron.'
        );
    }

    public function test_los_formrequest_validan_exactamente_esos_cargos(): void
    {
        $store = (new StoreTenantRequest)->rules()['tipo_cargo'];

        // Es la comparación que la 0080 no podía hacer: hoy las dos fuentes que
        // quedan son la misma lista, y esta prueba se cae el día que dejen de
        // serlo. La tercera —la regla de edición— desapareció en la 0093 al
        // hacerse inmutable el cargo; ver la prueba de abajo.
        $this->assertEqualsCanonicalizing(Tenant::TIPOS_CARGO, $this->valoresDeLaRegla($store));
        $this->assertEqualsCanonicalizing(Tenant::TIPOS_CARGO, $this->cargosDeLaColumna());
    }

    public function test_la_edicion_ya_no_acepta_el_cargo(): void
    {
        // Inmutable desde la 0093: el cargo **es** la elección de la campaña, y
        // de ella cuelga todo el escrutinio. Cambiarlo después dejaría las actas
        // ya cargadas colgando de una elección que la campaña dice no tener. Al
        // no estar en las reglas no llega a `validated()`, así que el `update()`
        // del controlador ni lo ve.
        $this->assertArrayNotHasKey('tipo_cargo', $this->reglasDeEdicion());
    }

    public function test_cada_cargo_valido_pasa_la_validacion(): void
    {
        $regla = (new StoreTenantRequest)->rules()['tipo_cargo'];

        foreach (Tenant::TIPOS_CARGO as $cargo) {
            $this->assertTrue(
                Validator::make(['tipo_cargo' => $cargo], ['tipo_cargo' => $regla])->passes(),
                "El cargo «{$cargo}» del enum no pasa la validación."
            );
        }
    }

    // ------------------------------------------------------ Cámara fuera

    public function test_representante_ya_no_es_un_cargo_elegible(): void
    {
        $this->assertNotContains('Representante', Tenant::TIPOS_CARGO);
        $this->assertNotContains('Representante', $this->cargosDeLaColumna());
    }

    public function test_dar_de_alta_una_campana_de_camara_responde_422(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/v1/tenants', [
            'slug' => 'camara-tolima',
            'nombre' => 'Cámara Tolima',
            'tipo_cargo' => 'Representante',
            'identificacion' => '900333444',
            'email_contacto' => 'contacto@camara-tolima.test',
            'admin_name' => 'Admin Cámara',
            'admin_email' => 'admin@camara-tolima.test',
            'admin_password' => 'secret1234',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo_cargo');

        // Y no se cuela a medias: sin campaña no hay admin ni roles clonados.
        $this->assertDatabaseMissing('tenants', ['slug' => 'camara-tolima']);
    }

    public function test_editar_una_campana_tampoco_la_deja_en_camara(): void
    {
        $this->actingAsSuperAdmin();

        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Concejo']);

        // Desde la 0093 la edición ni siquiera discute el cargo: se ignora, así
        // que la petición pasa y el cargo se queda como estaba. Cámara sigue
        // siendo igual de imposible, por otro camino.
        $this->putJson("/api/v1/tenants/{$tenant->slug}", ['tipo_cargo' => 'Representante'])
            ->assertStatus(200);

        $this->assertSame('Concejo', $tenant->fresh()->tipo_cargo);
    }

    // --------------------------------------------- el mapeo de la 0080

    public function test_el_mapeo_de_cargo_a_eleccion_no_se_movio(): void
    {
        $resolver = app(EventoResolver::class);

        // Traducir por texto acertaría en tres y fallaría en los dos que
        // importan: un diputado se elige en la asamblea y un congresista, en el
        // senado (Spec 0080).
        $this->assertSame('alcaldia', $resolver->tipoDelCargo('Alcaldia'));
        $this->assertSame('gobernacion', $resolver->tipoDelCargo('Gobernacion'));
        $this->assertSame('concejo', $resolver->tipoDelCargo('Concejo'));
        $this->assertSame('asamblea_departamental', $resolver->tipoDelCargo('Diputado'));
        $this->assertSame('senado', $resolver->tipoDelCargo('Congresista'));
        $this->assertNull($resolver->tipoDelCargo('Otro'));
    }

    public function test_todo_cargo_elegible_menos_otro_tiene_su_eleccion(): void
    {
        $resolver = app(EventoResolver::class);

        foreach (Tenant::TIPOS_CARGO as $cargo) {
            $tipo = $resolver->tipoDelCargo($cargo);

            if ($cargo === 'Otro') {
                // El catch-all se queda sin mapear a propósito: no es un cargo de
                // elección popular y no hay elección que inventarle.
                $this->assertNull($tipo);

                continue;
            }

            // Lo que se puede elegir se puede escrutar: era justo lo que
            // `Representante` no cumplía.
            $this->assertNotNull($tipo, "El cargo «{$cargo}» no tiene elección donde escrutar.");
        }
    }

    public function test_un_cargo_que_ya_no_existe_no_mapea_a_ninguna_eleccion(): void
    {
        $resolver = app(EventoResolver::class);

        // Por si quedara una campaña vieja con el valor a mano: sin elección, el
        // cruce avisa en vez de escribir en la equivocada.
        $this->assertNull($resolver->tipoDelCargo('Representante'));
    }
}
