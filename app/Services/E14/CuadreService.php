<?php

namespace App\Services\E14;

use App\Models\E14Acta;

/**
 * La regla que decide si un resultado electoral se publica (Spec 0061).
 *
 *     Σ candidatos + blanco + nulos + no marcados = suma declarada = urna nivelada
 *     urna nivelada = votos en la urna − votos incinerados
 *
 * La urna es la **nivelada** y no la cruda (Spec 0088): los votos que los jurados
 * incineran para nivelar la mesa están en la urna y no en ninguna casilla, así
 * que compararlas contra la cruda descuadraba en falso toda acta con
 * incineración. Con cero incinerados —el caso normal— no cambia nada.
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
 * Aparte va la nivelación (`votantes_e11 − urna nivelada`). Que alguien se
 * registrara y no depositara es una novedad del acta, no un error de lectura, y
 * por eso se anota sin bloquear nada. Con la mesa bien nivelada da cero.
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

    /**
     * La urna ya nivelada: los votos que de verdad se contaron (Spec 0088).
     *
     * La regla es de la Registraduría y es exacta: **urna − incinerados =
     * sufragantes (E-11)**. Cuando en la urna hay más votos que sufragantes, los
     * jurados extraen al azar el excedente y lo **incineran sin abrirlo** para
     * nivelar la mesa, así que las casillas del acta suman la urna nivelada y no
     * la cruda.
     *
     * Se acota a cero: más incinerados que votos en la urna es un acta mal
     * diligenciada, y un negativo convertiría un dato imposible en una
     * aritmética imposible.
     */
    public static function urnaEfectiva(int $votosUrna, int $votosIncinerados): int
    {
        return max(0, $votosUrna - $votosIncinerados);
    }

    public function evaluar(
        int $sumaCandidatos,
        int $votosBlanco,
        int $votosNulos,
        int $votosNoMarcados,
        int $sumaDeclarada,
        int $votosUrna,
        int $votantesE11,
        int $votosIncinerados = 0,
    ): Cuadre {
        $calculada = $sumaCandidatos + $votosBlanco + $votosNulos + $votosNoMarcados;
        $urnaEfectiva = self::urnaEfectiva($votosUrna, $votosIncinerados);

        // Antes que nada: sin votos no hay escrutinio. `votantes_e11` **no**
        // rescata el cuadre —que el E-11 diga que se registraron 250 personas no
        // convierte en resultado un acta donde no se leyó un solo voto—, pero la
        // nivelación se anota igual, con la misma fórmula de siempre.
        //
        // El guardia mira la urna **nivelada** (0088): una urna que se incineró
        // entera tampoco deja nada que contar, y `0 = 0 = 0` volvería a colar el
        // acta vacía por la puerta que la 0077 cerró.
        if ($calculada === 0 && $sumaDeclarada === 0 && $urnaEfectiva === 0) {
            return new Cuadre(
                cuadra: false,
                estado: E14Acta::ESTADO_REVISION_MANUAL,
                motivo: self::MOTIVO_SIN_DATOS,
                sumaCalculada: 0,
                sumaDeclarada: 0,
                votosUrna: $votosUrna,
                difNivelacion: $votantesE11 - $urnaEfectiva,
                votosIncinerados: $votosIncinerados,
                urnaEfectiva: $urnaEfectiva,
            );
        }

        $motivos = $this->motivoDeLaNivelacion($votosUrna, $votosIncinerados);

        if ($calculada !== $sumaDeclarada) {
            $motivos[] = "la suma de las casillas ({$calculada}) no coincide con la suma declarada en el acta ({$sumaDeclarada})";
        }

        if ($sumaDeclarada !== $urnaEfectiva) {
            $motivos[] = "la suma declarada ({$sumaDeclarada}) no coincide con "
                .$this->describirUrna($votosUrna, $votosIncinerados);
        }

        return new Cuadre(
            cuadra: $motivos === [],
            estado: $motivos === [] ? E14Acta::ESTADO_PROCESADA : E14Acta::ESTADO_INCONSISTENTE,
            motivo: implode('; ', $motivos),
            sumaCalculada: $calculada,
            sumaDeclarada: $sumaDeclarada,
            votosUrna: $votosUrna,
            difNivelacion: $votantesE11 - $urnaEfectiva,
            votosIncinerados: $votosIncinerados,
            urnaEfectiva: $urnaEfectiva,
        );
    }

    /**
     * Cómo se nombra la urna en un motivo de revisión (Spec 0088).
     *
     * Sin incineración, la copia de siempre. Con ella, la cuenta va a la vista:
     * quien revisa tiene el papel delante, y un «no coincide con 253» frente a
     * un acta que dice 254 parece el error del sistema hasta que se ve el
     * descuento.
     */
    private function describirUrna(int $votosUrna, int $votosIncinerados): string
    {
        if ($votosIncinerados === 0) {
            return "los votos en la urna ({$votosUrna})";
        }

        $efectiva = self::urnaEfectiva($votosUrna, $votosIncinerados);
        $nombre = $votosIncinerados === 1 ? 'incinerado' : 'incinerados';

        return "la urna nivelada ({$efectiva} = {$votosUrna} − {$votosIncinerados} {$nombre})";
    }

    /**
     * El aviso de una nivelación imposible, si la hay (Spec 0088 · RF-6).
     *
     * Incinerar más votos de los que había en la urna no pasa en un acta bien
     * diligenciada. No se aproxima ni se ignora: la urna efectiva se acota a
     * cero y el acta se va a revisión **diciendo por qué**, porque el descuadre
     * que arrastra detrás no se entiende sin esto.
     *
     * @return array<int, string>
     */
    private function motivoDeLaNivelacion(int $votosUrna, int $votosIncinerados): array
    {
        if ($votosIncinerados <= $votosUrna) {
            return [];
        }

        return ["el acta declara más votos incinerados ({$votosIncinerados}) que votos "
            ."en la urna ({$votosUrna}): la nivelación de la mesa está mal diligenciada"];
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
        int $votosIncinerados = 0,
    ): Cuadre {
        $totalListas = 0;

        foreach ($listas as $lista) {
            $totalListas += $lista->totalDeclarado;
        }

        $calculada = $totalListas + $votosBlanco + $votosNulos + $votosNoMarcados;
        $urnaEfectiva = self::urnaEfectiva($votosUrna, $votosIncinerados);

        // El mismo guardia de la 0077, al nivel que toca: si no hay nada que
        // contar, no hay nada que cuadrar. `0 = 0` lo cumpliría en silencio y el
        // acta entraría al consolidado aportando nada.
        if ($calculada === 0 && $urnaEfectiva === 0) {
            return new Cuadre(
                cuadra: false,
                estado: E14Acta::ESTADO_REVISION_MANUAL,
                motivo: self::MOTIVO_SIN_DATOS,
                sumaCalculada: 0,
                sumaDeclarada: 0,
                votosUrna: $votosUrna,
                difNivelacion: $votantesE11 - $urnaEfectiva,
                votosIncinerados: $votosIncinerados,
                urnaEfectiva: $urnaEfectiva,
            );
        }

        $motivos = $this->motivoDeLaNivelacion($votosUrna, $votosIncinerados);
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

        // Nivel 2: el acta entera contra la urna ya nivelada (0088).
        if ($calculada !== $urnaEfectiva) {
            $motivos[] = "la suma de las casillas ({$calculada}) no coincide con "
                .$this->describirUrna($votosUrna, $votosIncinerados);
        }

        return new Cuadre(
            cuadra: $motivos === [],
            estado: $motivos === [] ? E14Acta::ESTADO_PROCESADA : E14Acta::ESTADO_INCONSISTENTE,
            motivo: implode('; ', $motivos),
            sumaCalculada: $calculada,
            // Se reporta en cero porque esa casilla no existe en el papel.
            sumaDeclarada: 0,
            votosUrna: $votosUrna,
            difNivelacion: $votantesE11 - $urnaEfectiva,
            listasDescuadradas: $descuadradas,
            votosIncinerados: $votosIncinerados,
            urnaEfectiva: $urnaEfectiva,
        );
    }
}
