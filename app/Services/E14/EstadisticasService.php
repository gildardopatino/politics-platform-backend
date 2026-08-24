<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use App\Models\ElectoralEvent;

/**
 * La materia prima de la exploración del escrutinio (Spec 0092 · Parte A).
 *
 * Una sola fila por mesa, con las dos mitades que hacen falta para explicar un
 * resultado, y **una sola llamada** para toda la página: el frontend pliega
 * desde aquí sus roll-ups —departamento, municipio, zona, puesto— sin volver a
 * preguntar por cada nivel del drill-down.
 *
 * ### Las dos mitades no miden lo mismo
 *
 * `base`, `votos_candidato`, `deficit` y `excedente` son **del candidato del
 * tenant**: su base identificada y la fila del E-14 con su número. `votos_urna`
 * y `votantes_e11` son **de la mesa entera** —todos los candidatos, más blancos,
 * nulos y no marcados—. Ponerlos en la misma fila es lo que permite leer «aquí
 * votaron 400 personas y yo saqué 12», pero dividir uno por el otro sin decirlo
 * inventaría una cuota de mercado que el acta no afirma.
 *
 * Y lo que **no** se puede calcular con esto es la participación real: eso sería
 * sufragantes ÷ censo, y el censo por mesa no existe en el modelo. Por eso aquí
 * solo viajan los volúmenes; el porcentaje se lo tendría que inventar quien lo
 * muestre (Spec 0092 §10).
 *
 * ### Por qué reusa el cruce en vez de contar otra vez
 *
 * El conteo de base y votos ya vive en `CruceService`, con sus tres reglas —solo
 * actas que cuadran, match exacto por puesto + mesa normalizada, base = los
 * `voters` del tenant—. Una segunda implementación sería una segunda cifra que
 * un día deja de cuadrar con «Potencial vs real» sin que nadie sepa cuál miente.
 * Aquí se le pide el nivel mesa y se le cose encima lo que el acta sabe.
 *
 * ### Qué cuenta como lectura
 *
 * La urna y los sufragantes salen de las actas **leídas** (`ESTADOS_LEIDA`), no
 * solo de las que cuadran: son transcripciones de casillas del formulario, y un
 * acta que no cuadra igual reporta cuánta gente votó ahí. `tiene_acta`, en
 * cambio, sigue siendo del cruce —solo `procesada`—, porque esa bandera dice si
 * los **votos** de esa mesa se pueden usar para juzgar a alguien.
 */
class EstadisticasService
{
    public function __construct(private readonly CruceService $cruce) {}

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    public function calcular(ElectoralEvent $evento, array $filtros = []): array
    {
        // El nivel no es negociable: la fila de esta página es la mesa, y de ahí
        // hacia arriba pliega el cliente. Va después de los filtros del panel
        // para que un `nivel=puesto` que llegue por la URL no la desarme.
        $cruce = $this->cruce->calcular($evento, array_replace($filtros, [
            'nivel' => CruceService::NIVEL_MESA,
        ]));

        // Después del cruce a propósito: él concilia los puestos de las actas
        // que llegaron sin `voting_place_id`, y sin eso la lectura de esas mesas
        // no encontraría con qué fila casarse.
        $filas = self::enriquecer($cruce['data'], $this->lecturas($evento));

        return [
            'data' => $filas,
            'meta' => array_replace(self::cobertura($filas), [
                'electoral_event_id' => $evento->id,
                'tipo' => $evento->tipo,
                'nivel' => CruceService::NIVEL_MESA,
                'candidato' => $cruce['meta']['candidato'],
            ]),
        ];
    }

    /**
     * Le cose a cada fila del cruce lo que el acta de **esa** mesa sabe.
     *
     * Pura y estática porque es el sitio exacto donde un descuido se ve como un
     * dato plausible: la llave es `voting_place_id + mesa`, nunca la mesa sola.
     * El «002» del Colegio San Simón y el «002» de la Escuela La Paz son dos
     * mesas distintas, y fundirlas daría un número que no es de ningún puesto.
     *
     * La fila que sale es el contrato completo, ni más ni menos: el cruce trae
     * además `rendimiento`, `diferencia` y `actas`, que son suyos y no se
     * publican aquí —la página deriva lo que necesita de base y votos, y cada
     * campo de más es contrato que luego hay que sostener—.
     *
     * @param  array<int, array<string, mixed>>  $filas  filas del cruce a nivel mesa
     * @param  array<string, array{zona: ?string, votos_urna: int, votantes_e11: int}>  $lecturas
     * @return array<int, array<string, mixed>>
     */
    public static function enriquecer(array $filas, array $lecturas): array
    {
        $vacia = ['zona' => null, 'votos_urna' => 0, 'votantes_e11' => 0];

        return array_map(function (array $fila) use ($lecturas, $vacia) {
            $clave = PuestoResolver::claveMesa((int) $fila['voting_place_id'], $fila['mesa'] ?? null);
            $lectura = $lecturas[$clave] ?? $vacia;

            return [
                'voting_place_id' => (int) $fila['voting_place_id'],
                'departamento' => $fila['departamento'],
                'municipio' => $fila['municipio'],
                'zona' => $lectura['zona'],
                'puesto' => $fila['puesto'],
                'mesa' => $fila['mesa'] ?? null,
                // Del candidato del tenant.
                'base' => (int) $fila['base'],
                'votos_candidato' => (int) $fila['votos_candidato'],
                'deficit' => (int) $fila['deficit'],
                'excedente' => (int) $fila['excedente'],
                'tiene_acta' => (bool) $fila['tiene_acta'],
                // De la mesa entera.
                'votos_urna' => (int) $lectura['votos_urna'],
                'votantes_e11' => (int) $lectura['votantes_e11'],
            ];
        }, $filas);
    }

    /**
     * El denominador del escrutinio: cuántas de las mesas que hay ya se contaron.
     *
     * Va en `meta` y no solo en las filas porque es la mitad del valor del
     * informe: la misma gráfica sobre 8 mesas de 20 no dice lo que dice sobre
     * 20 de 20, y sin el par de números las dos se leen igual. Los cortes por
     * nivel los hace el cliente sobre `data` — son el mismo conteo, filtrado.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array{total: int, con_acta: int}
     */
    public static function cobertura(array $filas): array
    {
        return [
            'total' => count($filas),
            'con_acta' => count(array_filter($filas, fn (array $fila) => (bool) $fila['tiene_acta'])),
        ];
    }

    /**
     * Lo que las actas leídas dicen de cada mesa, indexado por la llave del cruce.
     *
     * **Una sola consulta** para toda la petición (Art. VI): con una por mesa,
     * un municipio mediano son miles de consultas por abrir la página.
     *
     * Las actas sin puesto canónico se quedan fuera, igual que en el cruce: sin
     * `voting_place_id` no hay fila con la que casarlas, y aproximarlas por
     * nombre es justo lo que la conciliación existe para no hacer.
     *
     * @return array<string, array{zona: ?string, votos_urna: int, votantes_e11: int}>
     */
    private function lecturas(ElectoralEvent $evento): array
    {
        $actas = E14Acta::query()
            ->where('electoral_event_id', $evento->id)
            ->whereIn('estado', E14Acta::ESTADOS_LEIDA)
            ->whereNotNull('voting_place_id')
            ->get(['voting_place_id', 'mesa', 'zona', 'votos_urna', 'votantes_e11']);

        $lecturas = [];

        foreach ($actas as $acta) {
            $clave = PuestoResolver::claveMesa(
                (int) $acta->voting_place_id,
                PuestoResolver::mesa($acta->mesa),
            );

            $previa = $lecturas[$clave] ?? ['zona' => null, 'votos_urna' => 0, 'votantes_e11' => 0];

            $lecturas[$clave] = [
                // La zona es un rótulo de la mesa, no una cantidad: la primera
                // que venga con nombre se queda. Si dos transcripciones de la
                // misma mesa discrepan, eso es un problema de las actas y se ve
                // en la revisión, no aquí.
                'zona' => $previa['zona'] ?? (blank($acta->zona) ? null : (string) $acta->zona),
                'votos_urna' => $previa['votos_urna'] + (int) $acta->votos_urna,
                'votantes_e11' => $previa['votantes_e11'] + (int) $acta->votantes_e11,
            ];
        }

        return $lecturas;
    }
}
