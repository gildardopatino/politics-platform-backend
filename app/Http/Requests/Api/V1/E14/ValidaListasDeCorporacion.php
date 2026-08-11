<?php

namespace App\Http\Requests\Api\V1\E14;

use App\Models\E14Acta;
use Illuminate\Validation\Validator;

/**
 * Las reglas del resultado anidado de corporación (Spec 0067 · Parte B · RF-B2).
 *
 * Viven en un trait porque las comparten las dos puertas de entrada —la ingesta
 * directa de la 0061 y la publicación del worker de la 0071— y el acta es la
 * misma por las dos. Duplicarlas es como acaban divergiendo.
 */
trait ValidaListasDeCorporacion
{
    /**
     * @return array<string, mixed>
     */
    protected function reglasDeListas(bool $ilegible, bool $corporacion): array
    {
        return [
            'listas' => $ilegible || ! $corporacion ? 'nullable|array' : 'required|array|min:1',

            // `distinct` aquí sí es lo que se quiere: una agrupación no puede
            // aparecer dos veces en la misma acta.
            //
            // Los números de lista llegan hasta cinco cifras (5170, 6497, 2642
            // en la muestra real): no caben en el `max:999` de los candidatos.
            'listas.*.lista_numero' => 'required|integer|min:1|max:99999|distinct',
            'listas.*.lista_nombre' => 'nullable|string|max:255',
            'listas.*.votos_solo_lista' => 'nullable|integer|min:0',
            'listas.*.total_agrupacion' => 'nullable|integer|min:0',
            'listas.*.con_voto_preferente' => 'nullable|boolean',

            // Una lista «sin voto preferente» llega con el array vacío: es una
            // maqueta del papel, no un error.
            'listas.*.preferentes' => 'nullable|array',
            // Sin `distinct`: esa regla compara **todas** las casillas que
            // encajan en el patrón, o sea entre listas distintas, y el mismo
            // número de preferencia en dos listas son dos personas — es el caso
            // normal, no un error. La unicidad que sí importa es dentro de cada
            // lista, y se comprueba en `validarPreferentesPorLista()`.
            'listas.*.preferentes.*.numero' => 'required|integer|min:1|max:999',
            'listas.*.preferentes.*.votos' => 'required|integer|min:0',
        ];
    }

    /**
     * Un número de preferencia no puede repetirse **dentro de su lista**.
     *
     * Es el índice único de la tabla dicho antes de llegar a la base: sin esto,
     * un payload con el 5 dos veces en la misma agrupación reventaría con un
     * error de integridad en vez de con un 422 que explique qué pasa.
     */
    protected function validarPreferentesPorLista(Validator $validator): void
    {
        foreach ((array) $this->input('listas', []) as $indice => $lista) {
            $vistos = [];

            foreach ((array) ($lista['preferentes'] ?? []) as $posicion => $preferente) {
                $numero = $preferente['numero'] ?? null;

                if ($numero === null) {
                    continue;
                }

                if (isset($vistos[$numero])) {
                    $validator->errors()->add(
                        "listas.{$indice}.preferentes.{$posicion}.numero",
                        'Un número de preferencia no puede repetirse dentro de la misma lista.'
                    );

                    continue;
                }

                $vistos[$numero] = true;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    protected function mensajesDeListas(): array
    {
        return [
            'listas.required' => 'Un acta de corporación legible tiene que traer sus agrupaciones políticas.',
            'listas.*.lista_numero.distinct' => 'Una agrupación política no puede aparecer dos veces en la misma acta.',
        ];
    }

    protected function esDeCorporacion(?string $tipo): bool
    {
        return E14Acta::esCorporacion($tipo);
    }
}
