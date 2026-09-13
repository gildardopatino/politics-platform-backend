<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo oficial de puestos de votación de Colombia (RNEC) con su ubicación
 * geográfica, como datos maestros versionados.
 *
 * Fuente: `database/seeders/data/voting_places.json` (13.741 puestos únicos por
 * la triple departamento+municipio+puesto). Incluye los consulados del exterior.
 * Las 2 filas con coordenadas imposibles quedaron con `latitud/longitud` en null.
 *
 * `voting_places` es un catálogo **global** (compartido entre campañas): lo usa
 * el `PuestoResolver` (Spec 0075) como base canónica del cruce E-14 y de la
 * consulta de Registraduría. Pre-sembrarlo con los nombres oficiales + coords
 * mejora el match y da geolocalización de entrada.
 *
 * **Idempotente** (Art. VIII): upsert por el índice único
 * `voting_place_unique`, así que una segunda corrida actualiza dirección y
 * coordenadas pero no duplica; corre limpio en `migrate:fresh --seed`.
 */
class VotingPlacesSeeder extends Seeder
{
    public function run(): void
    {
        $ruta = database_path('seeders/data/voting_places.json');

        if (! is_file($ruta)) {
            $this->command?->warn("No se encontró {$ruta}; se omite VotingPlacesSeeder.");

            return;
        }

        $filas = json_decode(file_get_contents($ruta), true);

        if (! is_array($filas) || $filas === []) {
            $this->command?->warn('voting_places.json vacío o inválido; se omite VotingPlacesSeeder.');

            return;
        }

        $ahora = now();
        $total = 0;

        // Lotes de 500 para no rozar el límite de parámetros del driver.
        foreach (array_chunk($filas, 500) as $lote) {
            $registros = array_map(fn (array $r): array => [
                'departamento_votacion' => $r['departamento_votacion'],
                'municipio_votacion' => $r['municipio_votacion'],
                'puesto_votacion' => $r['puesto_votacion'],
                'direccion_votacion' => $r['direccion_votacion'] ?? null,
                'latitud' => $r['latitud'] ?? null,
                'longitud' => $r['longitud'] ?? null,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ], $lote);

            DB::table('voting_places')->upsert(
                $registros,
                ['departamento_votacion', 'municipio_votacion', 'puesto_votacion'],
                ['direccion_votacion', 'latitud', 'longitud', 'updated_at'],
            );

            $total += count($registros);
        }

        $this->command?->info("Puestos de votación cargados/actualizados: {$total}.");
    }
}
