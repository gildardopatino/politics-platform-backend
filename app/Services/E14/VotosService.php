<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use App\Models\E14Resultado;
use App\Models\ElectoralEvent;

/**
 * El lado «real» del escrutinio: cuántos votos sacó mi candidato en cada puesto
 * (y mesa), y dónde hay acta (Specs 0062, 0076 y 0063).
 *
 * Vive aparte del cruce porque ya son **dos** las pantallas que hacen la misma
 * pregunta —el cruce contra la base identificada y el rendimiento de líderes— y
 * la respuesta tiene que ser idéntica en las dos. Con el SQL duplicado, el día
 * que alguien cambie qué estados cuentan o cómo se normaliza la mesa en un
 * sitio y no en el otro, los dos informes empiezan a contradecirse y nadie sabe
 * cuál mentía.
 *
 * Dos reglas gobiernan lo que devuelve, heredadas de la 0062:
 *
 * 1. **Solo actas `procesada`.** Una mesa que no cuadra consigo misma no sirve
 *    para juzgar el rendimiento de nadie.
 * 2. **Solo actas con puesto canónico.** Lo que no resuelve no se aproxima: se
 *    cuenta en la cobertura del cruce y se manda a conciliar.
 */
class VotosService
{
    /**
     * Los votos de mi candidato, por puesto (y mesa).
     *
     * @param  'puesto'|'mesa'  $nivel
     * @return array<string, int> llave de `PuestoResolver::claveMesa()` → votos
     */
    public function deMiCandidato(ElectoralEvent $evento, string $nivel): array
    {
        $filas = E14Resultado::query()
            ->join('e14_actas', 'e14_actas.id', '=', 'e14_resultados.e14_acta_id')
            ->where('e14_actas.electoral_event_id', $evento->id)
            ->where('e14_actas.estado', E14Acta::ESTADO_PROCESADA)
            ->whereNotNull('e14_actas.voting_place_id')
            ->where('e14_resultados.numero', $evento->candidato_propio_numero)
            ->selectRaw('e14_actas.voting_place_id as voting_place_id')
            ->selectRaw('e14_actas.mesa as mesa')
            ->selectRaw('SUM(e14_resultados.votos) as votos')
            ->groupBy('e14_actas.voting_place_id', 'e14_actas.mesa')
            ->get();

        return $this->agrupar($filas, $nivel, 'votos');
    }

    /**
     * Cuántas actas `procesada` hay en cada puesto (y mesa).
     *
     * Se cuentan aparte de los votos porque son dos preguntas distintas: «¿hay
     * acta de este puesto?» y «¿cuántos votos sacó mi candidato ahí?». Un puesto
     * donde mi candidato sacó cero votos **tiene** acta, y confundirlo con uno
     * sin escrutar sería leer un cero real como un dato que falta.
     *
     * @param  'puesto'|'mesa'  $nivel
     * @return array<string, int> llave de `PuestoResolver::claveMesa()` → actas
     */
    public function actasProcesadas(ElectoralEvent $evento, string $nivel): array
    {
        $filas = E14Acta::query()
            ->where('electoral_event_id', $evento->id)
            ->where('estado', E14Acta::ESTADO_PROCESADA)
            ->whereNotNull('voting_place_id')
            ->selectRaw('voting_place_id')
            ->selectRaw('mesa')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('voting_place_id', 'mesa')
            ->get();

        return $this->agrupar($filas, $nivel, 'total');
    }

    /**
     * Pliega las filas de SQL a la llave del cruce.
     *
     * La agrupación por mesa se termina aquí y no en el `GROUP BY` porque `005`
     * y `5` son la misma mesa y ningún motor lo sabe: normalizar en SQL es lo
     * que la 0019 dejó dicho que no se hace.
     *
     * @param  iterable<int, object>  $filas
     * @return array<string, int>
     */
    private function agrupar(iterable $filas, string $nivel, string $columna): array
    {
        $conteo = [];

        foreach ($filas as $fila) {
            $clave = PuestoResolver::claveMesa(
                (int) $fila->voting_place_id,
                $nivel === CruceService::NIVEL_MESA ? PuestoResolver::mesa($fila->mesa) : null
            );

            $conteo[$clave] = ($conteo[$clave] ?? 0) + (int) $fila->{$columna};
        }

        return $conteo;
    }
}
