<?php

namespace App\Services\E14;

use App\Models\E14Acta;

/**
 * La regla que decide si un resultado electoral se publica (Spec 0061).
 *
 *     Σ candidatos + blanco + nulos + no marcados = suma declarada = votos en la urna
 *
 * El lector ya hace esta cuenta antes de enviar, y aun así el servidor la repite
 * con los números crudos que recibió. No es desconfianza del lector en concreto:
 * el `estado` es el campo que decide qué votos entran al consolidado, así que no
 * puede depender de que el cliente lo calcule bien. Que ambos lados manden su
 * veredicto y este gane es lo que permite que una discrepancia se note.
 *
 * Lo que **no** hace es corregir. Un acta que no cuadra se marca
 * `inconsistente` con el motivo y se queda fuera del consolidado hasta que una
 * persona mire el papel: cuadrar un acta a mano es inventarse un resultado.
 *
 * Aparte va la nivelación (`votantes_e11 − votos_urna`). Que alguien se
 * registrara y no depositara es una novedad del acta, no un error de lectura, y
 * por eso se anota sin bloquear nada.
 *
 * ### El acta en blanco (Spec 0077)
 *
 * La igualdad de arriba tiene un cero a la izquierda: `0 = 0 = 0` la cumple. Un
 * acta que el lector no consiguió transcribir —ninguna casilla, la urna vacía—
 * salía por eso **procesada**, entraba al consolidado aportando nada y
 * desaparecía de la cola de revisión. Es la peor forma de fallar: no se ve.
 *
 * De ahí el guardia de `sinDatos()`, antes de las comparaciones. No es una regla
 * de cuadre más, es la pregunta previa: **si no hay nada que contar, no hay nada
 * que cuadrar**. El acta va a `revision_manual`, que es donde una persona puede
 * releerla, corregirla o quitarla.
 */
class CuadreService
{
    /**
     * Lo que se le dice a quien abra el acta. Va como `observacion`, así que
     * tiene que explicar el estado por sí solo, sin obligar a mirar las cifras.
     */
    public const MOTIVO_SIN_DATOS = 'el acta no tiene datos: ninguna casilla ni la urna registran votos';

    public function evaluar(
        int $sumaCandidatos,
        int $votosBlanco,
        int $votosNulos,
        int $votosNoMarcados,
        int $sumaDeclarada,
        int $votosUrna,
        int $votantesE11,
    ): Cuadre {
        $calculada = $sumaCandidatos + $votosBlanco + $votosNulos + $votosNoMarcados;

        // Antes que nada: sin votos no hay escrutinio. `votantes_e11` **no**
        // rescata el cuadre —que el E-11 diga que se registraron 250 personas no
        // convierte en resultado un acta donde no se leyó un solo voto—, pero la
        // nivelación se anota igual, con la misma fórmula de siempre.
        if ($calculada === 0 && $sumaDeclarada === 0 && $votosUrna === 0) {
            return new Cuadre(
                cuadra: false,
                estado: E14Acta::ESTADO_REVISION_MANUAL,
                motivo: self::MOTIVO_SIN_DATOS,
                sumaCalculada: 0,
                sumaDeclarada: 0,
                votosUrna: 0,
                difNivelacion: $votantesE11 - $votosUrna,
            );
        }

        $motivos = [];

        if ($calculada !== $sumaDeclarada) {
            $motivos[] = "la suma de las casillas ({$calculada}) no coincide con la suma declarada en el acta ({$sumaDeclarada})";
        }

        if ($sumaDeclarada !== $votosUrna) {
            $motivos[] = "la suma declarada ({$sumaDeclarada}) no coincide con los votos en la urna ({$votosUrna})";
        }

        return new Cuadre(
            cuadra: $motivos === [],
            estado: $motivos === [] ? E14Acta::ESTADO_PROCESADA : E14Acta::ESTADO_INCONSISTENTE,
            motivo: implode('; ', $motivos),
            sumaCalculada: $calculada,
            sumaDeclarada: $sumaDeclarada,
            votosUrna: $votosUrna,
            difNivelacion: $votantesE11 - $votosUrna,
        );
    }

    /**
     * El cuadre de un acta de corporación (Spec 0067 · Parte B · RF-B3).
     *
     * Dos niveles, y el de abajo es el que hace útil el error:
     *
     *     por lista:  votos solo lista + Σ preferentes = total de la agrupación
     *     global:     Σ totales de agrupación + controles = votos en la urna
     *
     * Un acta de diecisiete agrupaciones que no cuadra por un voto no le dice
     * nada a quien la revise; el self-check **nombra la lista**, que es
     * señalarle la hoja. Ya se ganó el sueldo en la primera acta real que leyó
     * el lector: la visión puso un voto en un renglón vacío del liberal y el
     * acta paró en `inconsistente` nombrándolo, en vez de publicar una cifra
     * inventada.
     *
     * ### Por qué aquí no hay `suma_declarada`
     *
     * El formulario de corporación **no trae** la casilla «SUMA TOTAL DE VOTOS
     * DEL ACTA E-14» que sí tiene el uninominal — confirmado sobre el papel al
     * transcribir el acta completa en 0067-A. El único contraste global es
     * contra la urna. Compararla contra cero habría mandado a revisión todas las
     * actas de concejo, así que ni se exige ni se guarda para estos tipos.
     *
     * ### El total global cuenta lo declarado, no lo recalculado
     *
     * Se suman los `total_agrupacion` tal como los escribió el jurado, que es lo
     * que alguien tallaría del papel. Recalcularlos desde las casillas taparía
     * justo el error que el self-check acaba de señalar.
     *
     * Esta es la misma lógica que el lector Python (0067-A) ya aplica antes de
     * publicar. Que los dos lados la hagan y este gane es lo que permite que una
     * discrepancia se note.
     *
     * @param  array<int, ListaCuadrable>  $listas
     */
    public function evaluarCorporacion(
        array $listas,
        int $votosBlanco,
        int $votosNulos,
        int $votosNoMarcados,
        int $votosUrna,
        int $votantesE11,
    ): Cuadre {
        $totalListas = 0;

        foreach ($listas as $lista) {
            $totalListas += $lista->totalDeclarado;
        }

        $calculada = $totalListas + $votosBlanco + $votosNulos + $votosNoMarcados;

        // El mismo guardia de la 0077, al nivel que toca: si no hay nada que
        // contar, no hay nada que cuadrar. `0 = 0` lo cumpliría en silencio y el
        // acta entraría al consolidado aportando nada.
        if ($calculada === 0 && $votosUrna === 0) {
            return new Cuadre(
                cuadra: false,
                estado: E14Acta::ESTADO_REVISION_MANUAL,
                motivo: self::MOTIVO_SIN_DATOS,
                sumaCalculada: 0,
                sumaDeclarada: 0,
                votosUrna: 0,
                difNivelacion: $votantesE11 - $votosUrna,
            );
        }

        $motivos = [];
        $descuadradas = [];

        // Nivel 1: cada agrupación contra su propio total. Va primero porque es
        // el error que se puede señalar con el dedo sobre el papel.
        foreach ($listas as $lista) {
            if ($lista->cuadra()) {
                continue;
            }

            $descuadradas[] = $lista->etiqueta();
            $motivos[] = "la lista {$lista->etiqueta()} declara {$lista->totalDeclarado} "
                ."pero sus casillas suman {$lista->sumaCalculada()} "
                ."(solo lista {$lista->votosSoloLista} + preferentes {$lista->sumaPreferentes})";
        }

        // Nivel 2: el acta entera contra la urna.
        if ($calculada !== $votosUrna) {
            $motivos[] = "la suma de las casillas ({$calculada}) no coincide con los votos en la urna ({$votosUrna})";
        }

        return new Cuadre(
            cuadra: $motivos === [],
            estado: $motivos === [] ? E14Acta::ESTADO_PROCESADA : E14Acta::ESTADO_INCONSISTENTE,
            motivo: implode('; ', $motivos),
            sumaCalculada: $calculada,
            // Se reporta en cero porque esa casilla no existe en el papel.
            sumaDeclarada: 0,
            votosUrna: $votosUrna,
            difNivelacion: $votantesE11 - $votosUrna,
            listasDescuadradas: $descuadradas,
        );
    }
}
