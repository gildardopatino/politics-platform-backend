<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use App\Models\E14Resultado;
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

        $porCandidato = $this->votosPorCandidato($ids);
        $totalCandidatos = array_sum(array_column($porCandidato, 'votos'));

        $votosBlanco = (int) ($controles->blanco ?? 0);
        $votosNulos = (int) ($controles->nulos ?? 0);
        $votosNoMarcados = (int) ($controles->no_marcados ?? 0);

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
