<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use App\Models\E14ListaPreferente;
use App\Models\ElectoralEvent;
use App\Scopes\TenantScope;

/**
 * El tarjetón de una corporación, derivado de lo que las actas leyeron (0082).
 *
 * En uninominal el catálogo es una tabla: `e14_candidates`, que la ingesta llena
 * sola con la primera acta que menciona a cada candidato. En corporación no hay
 * equivalente y no debería haberlo — el E-14 de corporación **no trae nombres**
 * de los preferentes, solo números (0067), así que no queda nada que catalogar
 * que no esté ya en el resultado.
 *
 * De ahí que esto se **derive** en vez de guardarse: las listas salen de
 * `e14_lista_resultados` y sus números de `e14_lista_preferentes`. Un catálogo
 * duplicado sería una segunda verdad sobre el mismo papel, y la primera vez que
 * alguien corrigiera un acta a mano las dos empezarían a discrepar.
 *
 * ### Solo las actas que cuadran
 *
 * Se filtran por `procesada`, igual que el consolidado. Un acta inconsistente
 * pudo leer mal el número de la lista, y ofrecer un «517» que en realidad era
 * «5170» dejaría fijar el candidato en una lista que no existe — que es
 * exactamente el error que la regla de los dos momentos (0062) quiere evitar.
 *
 * Es una asimetría consciente con el uninominal, cuyo catálogo se llena en la
 * ingesta sin mirar el estado. Aquí se puede ser más estricto porque el dato ya
 * está en el resultado, y conviene serlo porque el número de lista es más largo
 * —hasta cinco cifras— y por tanto más fácil de transcribir mal.
 */
class CatalogoDeCorporacion
{
    /**
     * Las listas del evento con sus números de preferencia.
     *
     * @return array<int, array{lista_numero: int, lista_nombre: string|null, preferentes: array<int, array{numero: int}>}>
     */
    public function paraEvento(?ElectoralEvent $evento): array
    {
        if ($evento === null) {
            return [];
        }

        // Sin `TenantScope`: la consulta ya va acotada por el evento, y una
        // elección pertenece a un solo tenant. Explícito para que también valga
        // desde consola, donde el scope no filtra.
        $filas = E14ListaPreferente::withoutGlobalScope(TenantScope::class)
            ->rightJoin(
                'e14_lista_resultados',
                'e14_lista_resultados.id',
                '=',
                'e14_lista_preferentes.e14_lista_resultado_id'
            )
            ->join('e14_actas', 'e14_actas.id', '=', 'e14_lista_resultados.e14_acta_id')
            ->where('e14_actas.electoral_event_id', $evento->id)
            ->where('e14_actas.estado', E14Acta::ESTADO_PROCESADA)
            ->selectRaw('e14_lista_resultados.lista_numero as lista_numero')
            // Con dos grafías del mismo partido se elige una y basta: el que
            // manda es el número, el nombre es para que se lea. `MIN` ignora los
            // nulos, así que un acta que no leyó el nombre no borra el que sí.
            ->selectRaw('MIN(e14_lista_resultados.lista_nombre) as lista_nombre')
            ->selectRaw('e14_lista_preferentes.numero as numero')
            ->groupBy('e14_lista_resultados.lista_numero', 'e14_lista_preferentes.numero')
            ->orderBy('e14_lista_resultados.lista_numero')
            ->orderBy('e14_lista_preferentes.numero')
            ->get();

        $listas = [];

        foreach ($filas as $fila) {
            $numeroDeLista = (int) $fila->lista_numero;

            $listas[$numeroDeLista] ??= [
                'lista_numero' => $numeroDeLista,
                'lista_nombre' => $fila->lista_nombre,
                'preferentes' => [],
            ];

            // El `rightJoin` trae la lista aunque no tenga ni un preferente: es
            // el caso de «LISTA SIN VOTO PREFERENTE», que existe en el tarjetón
            // y no tiene números. Ahí `numero` viene nulo y no hay qué añadir.
            if ($fila->numero !== null) {
                $listas[$numeroDeLista]['preferentes'][] = ['numero' => (int) $fila->numero];
            }
        }

        return array_values($listas);
    }

    /**
     * @param  array<int, array<string, mixed>>  $catalogo
     */
    public function tieneLista(array $catalogo, int $listaNumero): bool
    {
        return $this->buscarLista($catalogo, $listaNumero) !== null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $catalogo
     */
    public function tienePreferente(array $catalogo, int $listaNumero, int $numero): bool
    {
        $lista = $this->buscarLista($catalogo, $listaNumero);

        if ($lista === null) {
            return false;
        }

        return in_array($numero, array_column($lista['preferentes'], 'numero'), true);
    }

    /**
     * El nombre del partido, para completar la agrupación al fijar (RF-1).
     *
     * @param  array<int, array<string, mixed>>  $catalogo
     */
    public function nombreDeLista(array $catalogo, int $listaNumero): ?string
    {
        return $this->buscarLista($catalogo, $listaNumero)['lista_nombre'] ?? null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $catalogo
     * @return array<string, mixed>|null
     */
    private function buscarLista(array $catalogo, int $listaNumero): ?array
    {
        foreach ($catalogo as $lista) {
            if ((int) $lista['lista_numero'] === $listaNumero) {
                return $lista;
            }
        }

        return null;
    }
}
