<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Geografía: departamentos, municipios y su árbol (Spec 0059).
 *
 * Antes este seeder inventaba dos departamentos de juguete, así que un
 * `migrate:fresh --seed` borraba la geografía real —48 municipios, 47 con el
 * contorno SVG que dibuja el mapa— y la dejaba irrecuperable. Ahora carga del
 * fixture versionado (`database/seeders/data/geography.json`, generado con
 * `php artisan geo:export`), y solo cae al demo mínimo si el archivo no está.
 *
 * Tres cosas que gobiernan la carga:
 *
 * - **Se escribe por query builder, no por modelo.** Las coordenadas reales
 *   viven en `latitude`/`longitude` y el modelo declara `latitud`/`longitud` en
 *   su `fillable`: pasar por Eloquent descartaría en silencio justo los datos
 *   que se quieren reponer.
 * - **Las relaciones se resuelven por `codigo`.** El fixture no trae ids porque
 *   no sobreviven a un reseed; cada nivel busca a su padre por código entre lo
 *   que se acaba de insertar.
 * - **Es idempotente por actualización, no por bandera.** Correrlo dos veces no
 *   duplica, y si el fixture cambió —un municipio nuevo, un `path` corregido—
 *   la segunda pasada lo aplica. Un guard de `count() > 0` habría dejado la
 *   geografía vieja congelada para siempre.
 */
class GeographySeeder extends Seeder
{
    public function run(): void
    {
        $fixture = $this->fixture();

        if ($fixture === null) {
            $this->demoMinimo();

            return;
        }

        $departamentos = $this->cargar('departments', $fixture['departments'] ?? []);

        $municipios = $this->cargar(
            'municipalities',
            $fixture['municipalities'] ?? [],
            ['department_id' => ['department_codigo', $departamentos]]
        );

        $comunas = $this->cargar(
            'communes',
            $fixture['communes'] ?? [],
            ['municipality_id' => ['municipality_codigo', $municipios]]
        );

        $corregimientos = $this->cargar(
            'corregimientos',
            $fixture['corregimientos'] ?? [],
            ['municipality_id' => ['municipality_codigo', $municipios]]
        );

        $this->cargar('barrios', $fixture['barrios'] ?? [], [
            'municipality_id' => ['municipality_codigo', $municipios],
            'commune_id' => ['commune_codigo', $comunas],
        ]);

        $this->cargar('veredas', $fixture['veredas'] ?? [], [
            'municipality_id' => ['municipality_codigo', $municipios],
            'corregimiento_id' => ['corregimiento_codigo', $corregimientos],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function fixture(): ?array
    {
        $ruta = config('geography.fixture') ?: database_path('seeders/data/geography.json');

        if (! is_file($ruta)) {
            return null;
        }

        $contenido = json_decode(file_get_contents($ruta), true);

        return is_array($contenido) ? $contenido : null;
    }

    /**
     * Inserta o actualiza un nivel y devuelve el mapa `codigo => id`, que es lo
     * que el nivel de abajo necesita para colgarse de su padre.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @param  array<string, array{0: string, 1: array<string, int>}>  $referencias
     * @return array<string, int>
     */
    private function cargar(string $tabla, array $filas, array $referencias = []): array
    {
        if (! Schema::hasTable($tabla)) {
            return [];
        }

        $columnas = null;
        $ids = [];

        foreach ($filas as $fila) {
            $codigo = $fila['codigo'] ?? null;

            if ($codigo === null) {
                continue;
            }

            $datos = [];

            foreach ($referencias as $columna => [$clave, $mapa]) {
                $codigoPadre = $fila[$clave] ?? null;
                $datos[$columna] = $codigoPadre !== null ? ($mapa[$codigoPadre] ?? null) : null;
            }

            foreach ($fila as $campo => $valor) {
                if (str_ends_with($campo, '_codigo')) {
                    continue; // las referencias ya se tradujeron a ids
                }

                $datos[$campo] = $valor;
            }

            // Solo lo que la tabla tiene de verdad: el esquema de este árbol
            // cambió con el tiempo (`latitud` vs `latitude`) y el fixture guarda
            // ambos para no perder nada.
            $columnas ??= Schema::getColumnListing($tabla);
            $datos = array_intersect_key($datos, array_flip($columnas));

            $existente = DB::table($tabla)->where('codigo', $codigo)->value('id');

            if ($existente) {
                DB::table($tabla)->where('id', $existente)->update($datos + ['updated_at' => now()]);
                $ids[$codigo] = $existente;

                continue;
            }

            $ids[$codigo] = DB::table($tabla)->insertGetId(
                $datos + ['created_at' => now(), 'updated_at' => now()]
            );
        }

        return $ids;
    }

    /**
     * Geografía de juguete para cuando no hay fixture: un clon del repositorio
     * sin el archivo, o una prueba que no lo necesita. Basta para que el resto
     * del sistema tenga con qué trabajar.
     */
    private function demoMinimo(): void
    {
        if (\App\Models\Department::count() > 0) {
            return;
        }

        $tolima = \App\Models\Department::create([
            'codigo' => '73',
            'nombre' => 'Tolima',
            'latitud' => 4.4389,
            'longitud' => -75.2322,
        ]);

        $cundinamarca = \App\Models\Department::create([
            'codigo' => '25',
            'nombre' => 'Cundinamarca',
            'latitud' => 5.0263,
            'longitud' => -73.9950,
        ]);

        $ibague = \App\Models\Municipality::create([
            'department_id' => $tolima->id,
            'codigo' => '73001',
            'nombre' => 'Ibagué',
            'latitud' => 4.4389,
            'longitud' => -75.2322,
        ]);

        \App\Models\Municipality::create([
            'department_id' => $cundinamarca->id,
            'codigo' => '11001',
            'nombre' => 'Bogotá D.C.',
            'latitud' => 4.7110,
            'longitud' => -74.0721,
        ]);

        $comuna = \App\Models\Commune::create([
            'municipality_id' => $ibague->id,
            'codigo' => '01',
            'nombre' => 'Comuna 1',
            'latitud' => 4.4400,
            'longitud' => -75.2300,
        ]);

        \App\Models\Barrio::create([
            'commune_id' => $comuna->id,
            'codigo' => '0101',
            'nombre' => 'El Centro',
            'latitud' => 4.4390,
            'longitud' => -75.2320,
        ]);

        $corregimiento = \App\Models\Corregimiento::create([
            'municipality_id' => $ibague->id,
            'codigo' => 'C01',
            'nombre' => 'Toche',
            'latitud' => 4.5000,
            'longitud' => -75.4000,
        ]);

        \App\Models\Vereda::create([
            'municipality_id' => $ibague->id,
            'corregimiento_id' => $corregimiento->id,
            'codigo' => 'V001',
            'nombre' => 'La Aldea',
            'latitud' => 4.5100,
            'longitud' => -75.4100,
        ]);
    }
}
