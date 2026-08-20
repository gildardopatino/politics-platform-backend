<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use App\Models\E14ListaPreferente;
use App\Models\E14ListaResultado;
use App\Models\E14Resultado;
use App\Models\ElectoralEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * Votos por candidato a partir de las actas que cuadran (Spec 0061 · Parte A).
 *
 * Solo entran las `procesada`. Una mesa cuyas casillas no suman lo que el acta
 * declara no está «casi bien»: sumarla al total sería publicar un número que
 * nadie puede sostener. Por eso el resumen dice además **cuántas actas se
 * quedaron fuera**, que es el dato que permite leer el consolidado con
 * criterio en vez de tomarlo por definitivo.
 */
class ConsolidadoService
{
    /**
     * @return array<string, mixed>
     */
    public function calcular(?int $eventoId = null, ?string $tipo = null): array
    {
        $procesadas = $this->actas($eventoId, $tipo)->where('estado', E14Acta::ESTADO_PROCESADA);

        $ids = (clone $procesadas)->pluck('id')->all();

        $controles = (clone $procesadas)
            ->selectRaw('COALESCE(SUM(votos_blanco), 0) as blanco')
            ->selectRaw('COALESCE(SUM(votos_nulos), 0) as nulos')
            ->selectRaw('COALESCE(SUM(votos_no_marcados), 0) as no_marcados')
            ->first();

        $votosBlanco = (int) ($controles->blanco ?? 0);
        $votosNulos = (int) ($controles->nulos ?? 0);
        $votosNoMarcados = (int) ($controles->no_marcados ?? 0);

        if (E14Acta::esCorporacion($tipo)) {
            return $this->deCorporacion(
                $eventoId, $tipo, $ids, $votosBlanco, $votosNulos, $votosNoMarcados
            );
        }

        $porCandidato = $this->votosPorCandidato($ids);
        $totalCandidatos = array_sum(array_column($porCandidato, 'votos'));

        return [
            'data' => $porCandidato,
            'meta' => [
                'actas' => $this->conteoPorEstado($eventoId, $tipo),
                'votos_blanco' => $votosBlanco,
                'votos_nulos' => $votosNulos,
                'votos_no_marcados' => $votosNoMarcados,
                'total_candidatos' => $totalCandidatos,
                'total_votos' => $totalCandidatos + $votosBlanco + $votosNulos + $votosNoMarcados,
            ],
            'desglose' => [
                'por_puesto' => $this->desglose($ids, 'puesto'),
                'por_zona' => $this->desglose($ids, 'zona'),
                // Por nombre del puesto (Spec 0074). Es el mismo corte que
                // `por_puesto`, pero legible: «COLEGIO SAN SIMON» en vez de «01».
                'por_lugar' => $this->desglose($ids, 'lugar'),
            ],
        ];
    }

    /**
     * Los votos de **mi candidato** en todo el evento, con la misma cuenta con
     * la que se arma el consolidado (Spec 0086).
     *
     * Existe porque la proyección necesitaba exactamente este número y lo estaba
     * sacando del `VotosService`, que solo mira actas con puesto conciliado: el
     * tablero de «¿voy ganando?» decía 50 donde el consolidado decía 411. Es la
     * misma pregunta —«¿cuántos votos llevo?»—, así que sale de la **misma**
     * cuenta; una tercera implementación sería una tercera cifra que un día deja
     * de cuadrar y nadie sabe cuál miente.
     *
     * Aquí **no** hay geo-filtro, a propósito: entran todas las `procesada`,
     * tengan puesto canónico o no. Dónde cayeron esos votos es otra pregunta, y
     * esa la contesta el desglose por puesto —que sí lo necesita—.
     *
     * @return array{votos: int, actas: int}
     */
    public function totalDeMiCandidato(ElectoralEvent $evento): array
    {
        $ids = $this->actas($evento->id, $evento->tipo)
            ->where('estado', E14Acta::ESTADO_PROCESADA)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return ['votos' => 0, 'actas' => 0];
        }

        $mias = E14Acta::esCorporacion($evento->tipo)
            // En corporación mi candidato es el par `(lista, preferente)`: el 5
            // de mi lista y el 5 de la de enfrente son dos personas distintas.
            ? array_filter(
                $this->votosPorPreferente($ids),
                fn (array $fila) => $fila['lista_numero'] === (int) $evento->candidato_propio_lista_numero
                    && $fila['numero'] === (int) $evento->candidato_propio_numero
            )
            : array_filter(
                $this->votosPorCandidato($ids),
                fn (array $fila) => $fila['numero'] === (int) $evento->candidato_propio_numero
            );

        return [
            'votos' => (int) array_sum(array_column($mias, 'votos')),
            'actas' => count($ids),
        ];
    }

    /**
     * El consolidado de una corporación, en dos niveles (Spec 0067 · RF-B4).
     *
     * `data` va por **lista** —la cifra con la que se reparten curules— y
     * `preferentes` por **(lista, número)**, que es quién se las lleva dentro de
     * la lista. Son dos cortes distintos del mismo escrutinio y los dos hacen
     * falta, así que viajan juntos en vez de obligar a dos llamadas.
     *
     * @param  array<int, int>  $ids
     * @return array<string, mixed>
     */
    private function deCorporacion(
        ?int $eventoId,
        ?string $tipo,
        array $ids,
        int $votosBlanco,
        int $votosNulos,
        int $votosNoMarcados,
    ): array {
        $porLista = $this->votosPorLista($ids);
        $totalListas = array_sum(array_column($porLista, 'votos'));

        return [
            'data' => $porLista,
            'preferentes' => $this->votosPorPreferente($ids),
            'meta' => [
                'actas' => $this->conteoPorEstado($eventoId, $tipo),
                'votos_blanco' => $votosBlanco,
                'votos_nulos' => $votosNulos,
                'votos_no_marcados' => $votosNoMarcados,
                'total_listas' => $totalListas,
                'total_votos' => $totalListas + $votosBlanco + $votosNulos + $votosNoMarcados,
            ],
        ];
    }

    /**
     * Lo que sacó cada agrupación, sumando lo que **declara** cada acta.
     *
     * Se suma `total_agrupacion` y no las casillas recalculadas por la misma
     * razón que en el cuadre: es la cifra que alguien tallaría del papel. Y solo
     * entran actas que cuadran, así que una lista mal sumada ya se quedó fuera
     * con su acta entera.
     *
     * @param  array<int, int>  $actaIds
     * @return array<int, array<string, mixed>>
     */
    private function votosPorLista(array $actaIds): array
    {
        if ($actaIds === []) {
            return [];
        }

        return E14ListaResultado::query()
            ->whereIn('e14_acta_id', $actaIds)
            ->selectRaw('lista_numero')
            // Con MIN se elige un nombre estable cuando dos actas lo
            // transcribieron distinto; el que manda es el número.
            ->selectRaw('MIN(lista_nombre) as lista_nombre')
            ->selectRaw('SUM(total_agrupacion) as votos')
            ->groupBy('lista_numero')
            ->orderBy('lista_numero')
            ->get()
            ->map(fn ($fila) => [
                'lista_numero' => (int) $fila->lista_numero,
                'lista_nombre' => $fila->lista_nombre,
                'votos' => (int) $fila->votos,
            ])
            ->all();
    }

    /**
     * Lo que sacó cada candidato por voto preferente, dentro de su lista.
     *
     * La clave es `(lista, número)` y no el número solo: el 1 de una lista y el
     * 1 de otra son dos personas, y fundirlos le regalaría los votos a una.
     *
     * @param  array<int, int>  $actaIds
     * @return array<int, array<string, mixed>>
     */
    private function votosPorPreferente(array $actaIds): array
    {
        if ($actaIds === []) {
            return [];
        }

        return E14ListaPreferente::query()
            ->join('e14_lista_resultados', 'e14_lista_resultados.id', '=', 'e14_lista_preferentes.e14_lista_resultado_id')
            ->whereIn('e14_lista_preferentes.e14_acta_id', $actaIds)
            ->selectRaw('e14_lista_resultados.lista_numero as lista_numero')
            ->selectRaw('MIN(e14_lista_resultados.lista_nombre) as lista_nombre')
            ->selectRaw('e14_lista_preferentes.numero as numero')
            ->selectRaw('SUM(e14_lista_preferentes.votos) as votos')
            ->groupBy('e14_lista_resultados.lista_numero', 'e14_lista_preferentes.numero')
            ->orderBy('e14_lista_resultados.lista_numero')
            ->orderBy('e14_lista_preferentes.numero')
            ->get()
            ->map(fn ($fila) => [
                'lista_numero' => (int) $fila->lista_numero,
                'lista_nombre' => $fila->lista_nombre,
                // Sin nombre del candidato: el acta de corporación no lo trae.
                'numero' => (int) $fila->numero,
                'votos' => (int) $fila->votos,
            ])
            ->all();
    }

    private function actas(?int $eventoId, ?string $tipo): Builder
    {
        return E14Acta::query()
            ->when($eventoId, fn (Builder $q) => $q->where('electoral_event_id', $eventoId))
            ->when($tipo, fn (Builder $q) => $q->where('tipo', $tipo));
    }

    /**
     * @return array<string, int>
     */
    private function conteoPorEstado(?int $eventoId, ?string $tipo): array
    {
        $conteo = $this->actas($eventoId, $tipo)
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->all();

        return [
            E14Acta::ESTADO_PROCESADA => (int) ($conteo[E14Acta::ESTADO_PROCESADA] ?? 0),
            E14Acta::ESTADO_INCONSISTENTE => (int) ($conteo[E14Acta::ESTADO_INCONSISTENTE] ?? 0),
            E14Acta::ESTADO_REVISION_MANUAL => (int) ($conteo[E14Acta::ESTADO_REVISION_MANUAL] ?? 0),
        ];
    }

    /**
     * @param  array<int, int>  $actaIds
     * @return array<int, array<string, mixed>>
     */
    private function votosPorCandidato(array $actaIds): array
    {
        if ($actaIds === []) {
            return [];
        }

        return E14Resultado::query()
            ->join('e14_candidates', 'e14_candidates.id', '=', 'e14_resultados.e14_candidate_id')
            ->whereIn('e14_resultados.e14_acta_id', $actaIds)
            ->selectRaw('e14_candidates.numero as numero')
            ->selectRaw('e14_candidates.nombre as nombre')
            ->selectRaw('e14_candidates.agrupacion as agrupacion')
            ->selectRaw('SUM(e14_resultados.votos) as votos')
            ->groupBy('e14_candidates.numero', 'e14_candidates.nombre', 'e14_candidates.agrupacion')
            ->orderBy('e14_candidates.numero')
            ->get()
            ->map(fn ($fila) => [
                'numero' => (int) $fila->numero,
                'nombre' => $fila->nombre,
                'agrupacion' => $fila->agrupacion,
                'votos' => (int) $fila->votos,
            ])
            ->all();
    }

    /**
     * Los mismos votos, abiertos por puesto o por zona.
     *
     * @param  array<int, int>  $actaIds
     * @return array<int, array<string, mixed>>
     */
    private function desglose(array $actaIds, string $eje): array
    {
        if ($actaIds === []) {
            return [];
        }

        $filas = E14Resultado::query()
            ->join('e14_actas', 'e14_actas.id', '=', 'e14_resultados.e14_acta_id')
            ->join('e14_candidates', 'e14_candidates.id', '=', 'e14_resultados.e14_candidate_id')
            ->whereIn('e14_resultados.e14_acta_id', $actaIds)
            ->selectRaw("e14_actas.{$eje} as eje")
            ->selectRaw('e14_candidates.numero as numero')
            ->selectRaw('e14_candidates.nombre as nombre')
            ->selectRaw('SUM(e14_resultados.votos) as votos')
            ->groupBy("e14_actas.{$eje}", 'e14_candidates.numero', 'e14_candidates.nombre')
            ->orderBy("e14_actas.{$eje}")
            ->orderBy('e14_candidates.numero')
            ->get();

        $agrupado = [];

        foreach ($filas as $fila) {
            // Un acta sin ese eje no hace grupo: un renglón «(sin nombre)» con
            // votos dentro se lee como si fuera un puesto de votación más.
            if (blank($fila->eje)) {
                continue;
            }

            $agrupado[$fila->eje] ??= [$eje => $fila->eje, 'candidatos' => [], 'total' => 0];
            $agrupado[$fila->eje]['candidatos'][] = [
                'numero' => (int) $fila->numero,
                'nombre' => $fila->nombre,
                'votos' => (int) $fila->votos,
            ];
            $agrupado[$fila->eje]['total'] += (int) $fila->votos;
        }

        return array_values($agrupado);
    }
}
