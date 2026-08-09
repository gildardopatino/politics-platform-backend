<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vuelca el árbol geográfico a un fixture versionable (Spec 0059).
 *
 * La geografía —con los `path` de los SVG que dibuja el mapa— es dato maestro que
 * costó cargar y que `migrate:fresh` borraba sin remedio, porque el seeder solo
 * creaba dos departamentos de juguete. Este comando la saca a JSON para que el
 * seeder pueda reponerla.
 *
 * Dos decisiones que valen para entender el formato:
 *
 * - **No se exportan ids.** Un `id` no sobrevive a un reseed; las relaciones se
 *   guardan por `codigo` del padre y el seeder las resuelve al cargar.
 * - **Solo columnas de geografía.** El comando lee una lista fija de campos, así
 *   que no hay forma de que se cuele un dato de persona por accidente: es data
 *   pública (nombre, código, coordenadas, contorno) y por eso puede vivir en el
 *   repositorio.
 */
class ExportGeography extends Command
{
    protected $signature = 'geo:export
                            {--path= : Dónde escribir el fixture}';

    protected $description = 'Exporta el árbol geográfico (con paths SVG) al fixture del seeder';

    /** Lo único que se copia de cada nivel. Ampliar aquí es una decisión consciente. */
    private const CAMPOS = ['codigo', 'nombre', 'latitud', 'longitud', 'latitude', 'longitude', 'path'];

    public function handle(): int
    {
        $destino = $this->option('path') ?: database_path('seeders/data/geography.json');

        if (! is_dir(dirname($destino))) {
            mkdir(dirname($destino), 0755, true);
        }

        $departamentos = $this->nivel('departments');

        $municipios = $this->nivel('municipalities', function ($fila) {
            return ['department_codigo' => $this->codigoDe('departments', $fila->department_id)];
        });

        $comunas = $this->nivel('communes', function ($fila) {
            return ['municipality_codigo' => $this->codigoDe('municipalities', $fila->municipality_id)];
        });

        $corregimientos = $this->nivel('corregimientos', function ($fila) {
            return ['municipality_codigo' => $this->codigoDe('municipalities', $fila->municipality_id)];
        });

        $barrios = $this->nivel('barrios', function ($fila) {
            return [
                'municipality_codigo' => $this->codigoDe('municipalities', $fila->municipality_id),
                'commune_codigo' => $this->codigoDe('communes', $fila->commune_id ?? null),
            ];
        });

        $veredas = $this->nivel('veredas', function ($fila) {
            return [
                'municipality_codigo' => $this->codigoDe('municipalities', $fila->municipality_id),
                'corregimiento_codigo' => $this->codigoDe('corregimientos', $fila->corregimiento_id ?? null),
            ];
        });

        $fixture = [
            'generated_at' => now()->toIso8601String(),
            'note' => 'Geografía pública (Spec 0059). Sin datos personales: solo nombre, código, coordenadas y contorno SVG.',
            'departments' => $departamentos,
            'municipalities' => $municipios,
            'communes' => $comunas,
            'corregimientos' => $corregimientos,
            'barrios' => $barrios,
            'veredas' => $veredas,
        ];

        file_put_contents(
            $destino,
            json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
        );

        $this->info('Fixture escrito en '.$destino);
        $this->table(
            ['Nivel', 'Filas', 'Con path'],
            collect(['departments', 'municipalities', 'communes', 'corregimientos', 'barrios', 'veredas'])
                ->map(fn (string $nivel) => [
                    $nivel,
                    count($fixture[$nivel]),
                    collect($fixture[$nivel])->filter(fn ($f) => ! empty($f['path']))->count(),
                ])
                ->all()
        );
        $this->line('Tamaño: '.number_format(filesize($destino) / 1024, 1).' KB');

        return self::SUCCESS;
    }

    /**
     * @param  callable|null  $referencias  claves extra (los códigos del padre)
     * @return array<int, array<string, mixed>>
     */
    private function nivel(string $tabla, ?callable $referencias = null): array
    {
        if (! Schema::hasTable($tabla)) {
            return [];
        }

        $campos = array_values(array_filter(
            self::CAMPOS,
            fn (string $campo) => Schema::hasColumn($tabla, $campo)
        ));

        return DB::table($tabla)
            ->orderBy('id')
            ->get()
            ->map(function ($fila) use ($campos, $referencias) {
                $registro = $referencias ? $referencias($fila) : [];

                foreach ($campos as $campo) {
                    $registro[$campo] = $fila->$campo;
                }

                return $registro;
            })
            ->all();
    }

    /** Traduce un id a su `codigo`, que es lo único estable entre reseeds. */
    private function codigoDe(string $tabla, ?int $id): ?string
    {
        if ($id === null || ! Schema::hasTable($tabla)) {
            return null;
        }

        static $cache = [];

        if (! isset($cache[$tabla])) {
            $cache[$tabla] = DB::table($tabla)->pluck('codigo', 'id')->all();
        }

        return $cache[$tabla][$id] ?? null;
    }
}
