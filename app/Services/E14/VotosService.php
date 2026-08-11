<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use App\Models\E14ListaPreferente;
use App\Models\E14Resultado;
use App\Models\ElectoralEvent;
use Illuminate\Database\Eloquent\Collection;

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
     * Quién es «mi candidato» depende del cargo, y por eso la consulta se
     * bifurca (Spec 0083): en uninominal es un número del tarjetón; en
     * corporación es una persona **dentro de una lista**, y su identidad es el
     * par `(lista, preferente)`. Lo que sale de aquí es lo mismo en los dos
     * casos —la misma tabla, la misma llave—, así que el cruce, el déficit y el
     * rendimiento de líderes no se enteran de la diferencia.
     *
     * @param  'puesto'|'mesa'  $nivel
     * @return array<string, int> llave de `PuestoResolver::claveMesa()` → votos
     */
    public function deMiCandidato(ElectoralEvent $evento, string $nivel): array
    {
        $filas = E14Acta::esCorporacion($evento->tipo)
            ? $this->filasDeCorporacion($evento)
            : $this->filasUninominales($evento);

        return $this->agrupar($filas, $nivel, 'votos');
    }

    /**
     * Uninominal: la fila del acta con mi número del tarjetón.
     *
     * @return Collection<int, E14Resultado>
     */
    private function filasUninominales(ElectoralEvent $evento): Collection
    {
        return E14Resultado::query()
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
    }

    /**
     * Corporación: la fila `(mi lista, mi preferente)` del acta.
     *
     * **Las dos condiciones, no una.** El número de preferencia se repite en
     * todas las listas del tarjetón —el 5 del partido A y el 5 del partido B
     * son dos personas distintas—, así que filtrar solo por `numero` le
     * atribuiría a mi candidato los votos de sus rivales. Por eso el `join`
     * baja hasta `e14_lista_resultados` y exige también la lista.
     *
     * Los `votos_solo_lista` **no** entran: son de la agrupación, no de ningún
     * candidato.
     *
     * Sigue siendo una sola agregada por evento, como la uninominal.
     *
     * @return Collection<int, E14ListaPreferente>
     */
    private function filasDeCorporacion(ElectoralEvent $evento): Collection
    {
        return E14ListaPreferente::query()
            ->join('e14_lista_resultados', 'e14_lista_resultados.id', '=', 'e14_lista_preferentes.e14_lista_resultado_id')
            ->join('e14_actas', 'e14_actas.id', '=', 'e14_lista_resultados.e14_acta_id')
            ->where('e14_actas.electoral_event_id', $evento->id)
            ->where('e14_actas.estado', E14Acta::ESTADO_PROCESADA)
            ->whereNotNull('e14_actas.voting_place_id')
            ->where('e14_lista_resultados.lista_numero', $evento->candidato_propio_lista_numero)
            ->where('e14_lista_preferentes.numero', $evento->candidato_propio_numero)
            ->selectRaw('e14_actas.voting_place_id as voting_place_id')
            ->selectRaw('e14_actas.mesa as mesa')
            ->selectRaw('SUM(e14_lista_preferentes.votos) as votos')
            ->groupBy('e14_actas.voting_place_id', 'e14_actas.mesa')
            ->get();
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
