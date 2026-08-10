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
 */
class CuadreService
{
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
