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
}
