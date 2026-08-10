<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use App\Models\ElectoralEvent;
use App\Models\VotingPlace;
use Illuminate\Validation\ValidationException;

/**
 * Déficit sobre la base identificada (Specs 0062 y 0076).
 *
 * Es la pregunta que paga la analítica electoral: en este puesto tengo 340
 * personas identificadas y mi candidato saco 120 votos — ¿qué pasó con las otras?
 *
 * ### Base ≠ censo (la corrección de la 0076)
 *
 * La 0062 llamó «registrados» a los `voters` del tenant y les aplicó una lógica que
 * solo tiene sentido contra el **censo electoral** del puesto: marcaba anomalía
 * cuando `votos > registrados`, con un «nadie puede votar donde no está
 * registrado» que salta casi siempre y es falso. Los `voters` no son el censo: son
 * la **base identificada** de la campaña —quien llegó por reuniones, call center,
 * líderes— y por diseño un **subconjunto** del electorado. El candidato recibe
 * votos de mucha gente que no está en el sistema, así que `votos > base` es lo
 * esperable y no informa de nada.
 *
 * La señal que **sí** tiene información cierta es la contraria, el **déficit**:
 * donde `votos < base`, al menos `base − votos` personas de la base no se
 * reflejaron en votos ahí. Ese es el piso de fuga y el sitio a donde ir a
 * preguntar. El caso inverso se reporta como **excedente**, dato neutro.
 *
 * Tres decisiones gobiernan el cálculo:
 *
 * 1. **Base = los `voters`** (opcionalmente `leads`) del tenant: es el único
 *    número que existe para todas las mesas.
 * 2. **Real = la fila del E-14 de mi candidato**, y solo de actas `procesada`.
 *    Una mesa que no cuadra consigo misma no se puede usar para juzgar a nadie.
 * 3. **El match es exacto** por `voting_place_id` + mesa normalizada. Lo que no
 *    resuelve no se aproxima: se cuenta en la cobertura y se manda a conciliar.
 *
 * Se calcula al preguntarlo y no se guarda: cambiar el candidato propio o fusionar
 * dos puestos tiene que verse en la siguiente llamada, sin recalcular nada.
 */
class CruceService
{
    public const NIVEL_PUESTO = 'puesto';

    public const NIVEL_MESA = 'mesa';

    /** Qué cuenta como base identificada del tenant (no como censo del puesto). */
    public const INCLUIR = RegistradosService::INCLUIR;

    public function __construct(
        private readonly PuestoResolver $puestos,
        private readonly RegistradosService $registrados,
        private readonly VotosService $votos,
    ) {}

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    public function calcular(ElectoralEvent $evento, array $filtros = []): array
    {
        if (! $evento->tieneCandidatoPropio()) {
            throw ValidationException::withMessages([
                'event' => 'Esta elección todavía no tiene candidato propio. '
                    .'Configúralo para poder cruzar tu base identificada con los votos reales.',
            ]);
        }

        $nivel = ($filtros['nivel'] ?? self::NIVEL_PUESTO) === self::NIVEL_MESA
            ? self::NIVEL_MESA
            : self::NIVEL_PUESTO;

        $incluir = RegistradosService::normalizarIncluir($filtros['incluir'] ?? null);

        // Las actas que se guardaron antes de que existiera el puesto canónico
        // —o antes de que su lugar estuviera en el catálogo— se resuelven aquí.
        // Es idempotente y evita una migración de datos que se quedaría corta con
        // la primera acta que llegue después.
        $this->puestos->conciliarActas($evento->id);

        $base = $this->registrados->contar($nivel, $incluir);
        $actas = $this->votos->actasProcesadas($evento, $nivel);
        $votos = $this->votos->deMiCandidato($evento, $nivel);

        $filas = $this->armarFilas($nivel, $base['grupos'], $actas, $votos);
        $filas = $this->filtrar($filas, $filtros);

        return [
            'data' => $filas,
            'meta' => [
                'electoral_event_id' => $evento->id,
                'nivel' => $nivel,
                'incluir' => $incluir,
                'candidato' => [
                    'numero' => $evento->candidato_propio_numero,
                    'nombre' => $evento->candidato_propio_nombre,
                    'agrupacion' => $evento->candidato_propio_agrupacion,
                ],
                'totales' => $this->totales($filas),
                'cobertura' => $this->cobertura($evento, $filas, $base),
            ],
        ];
    }

    /**
     * Une los tres lados y le pone nombre a cada puesto.
     *
     * @param  array<string, array<string, mixed>>  $base
     * @param  array<string, int>  $actas
     * @param  array<string, int>  $votos
     * @return array<int, array<string, mixed>>
     */
    private function armarFilas(string $nivel, array $base, array $actas, array $votos): array
    {
        $filas = $base;

        // Un puesto con acta pero sin base también es una fila: ahí hay votos de
        // gente que la campaña no tiene identificada, y eso se quiere ver.
        foreach (array_keys($actas + $votos) as $clave) {
            if (isset($filas[$clave])) {
                continue;
            }

            [$puesto, $mesa] = PuestoResolver::partirClaveMesa($clave);
            $filas[$clave] = ['voting_place_id' => $puesto, 'mesa' => $mesa, 'base' => 0];
        }

        $lugares = VotingPlace::query()
            ->whereIn('id', array_column($filas, 'voting_place_id'))
            ->get(['id', 'departamento_votacion', 'municipio_votacion', 'puesto_votacion'])
            ->keyBy('id');

        $armadas = [];

        foreach ($filas as $clave => $fila) {
            $lugar = $lugares->get($fila['voting_place_id']);
            $cuenta = $fila['base'];
            $misVotos = $votos[$clave] ?? 0;

            $armada = [
                'voting_place_id' => $fila['voting_place_id'],
                'departamento' => $lugar?->departamento_votacion,
                'municipio' => $lugar?->municipio_votacion,
                'puesto' => $lugar?->puesto_votacion,
                'base' => $cuenta,
                'votos_candidato' => $misVotos,
                'rendimiento' => $this->rendimiento($misVotos, $cuenta),
                'diferencia' => $misVotos - $cuenta,
                // Las dos mitades de `diferencia`, separadas porque no valen lo
                // mismo: el **déficit** es gente de la base que no se reflejó en
                // votos aquí —dato cierto y accionable—, y el **excedente** son
                // votos de fuera de la base, que es lo normal y no reclama nada.
                'deficit' => max(0, $cuenta - $misVotos),
                'excedente' => max(0, $misVotos - $cuenta),
                'tiene_acta' => ($actas[$clave] ?? 0) > 0,
                'actas' => $actas[$clave] ?? 0,
            ];

            // `mesa` solo aparece cuando se pidió ese nivel: una columna que
            // siempre viene en null invita a mostrarla vacía.
            if ($nivel === self::NIVEL_MESA) {
                $armada['mesa'] = $fila['mesa'];
            }

            $armadas[] = $armada;
        }

        usort($armadas, fn (array $a, array $b) => [$a['municipio'], $a['puesto'], $a['mesa'] ?? 0]
            <=> [$b['municipio'], $b['puesto'], $b['mesa'] ?? 0]);

        return $armadas;
    }

    /**
     * Filtros del panel, aplicados sobre las filas ya armadas.
     *
     * Van aquí y no en las consultas porque las filas se arman uniendo tres
     * agregaciones sobre tablas distintas: filtrar en SQL obligaría a repetir el
     * mismo criterio tres veces —y a mantener las tres iguales— para llegar al
     * mismo sitio. Las filas están acotadas por el número de puestos.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<string, mixed>>
     */
    private function filtrar(array $filas, array $filtros): array
    {
        $municipio = $filtros['municipio'] ?? null;
        $puesto = $filtros['voting_place'] ?? null;

        return array_values(array_filter($filas, function (array $fila) use ($municipio, $puesto) {
            if (filled($municipio) && ! $this->contiene($fila['municipio'], $municipio)) {
                return false;
            }

            if (blank($puesto)) {
                return true;
            }

            // El panel manda el id cuando el usuario eligió de la lista, y texto
            // cuando escribió en la casilla: las dos formas de preguntar por lo
            // mismo, igual que los filtros de ubicación de la 0074.
            return ctype_digit((string) $puesto)
                ? $fila['voting_place_id'] === (int) $puesto
                : $this->contiene($fila['puesto'], $puesto);
        }));
    }

    /**
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    private function totales(array $filas): array
    {
        $base = (int) array_sum(array_column($filas, 'base'));
        $votos = (int) array_sum(array_column($filas, 'votos_candidato'));
        $deficits = array_column($filas, 'deficit');

        return [
            'puestos' => count($filas),
            'base' => $base,
            'votos_candidato' => $votos,
            'rendimiento' => $this->rendimiento($votos, $base),
            // La diferencia global **se compensa sola**: un puesto que rindió de
            // sobra tapa a otro que se quedó corto. Por eso el número de cabecera
            // es `deficit_total`, que suma fila por fila y no se cancela.
            'diferencia' => $votos - $base,
            'deficit_total' => (int) array_sum($deficits),
            'excedente_total' => (int) array_sum(array_column($filas, 'excedente')),
            'puestos_con_deficit' => count(array_filter($deficits)),
        ];
    }

    /**
     * Calidad del cruce. Es la mitad del valor del informe: un 80 % de rendimiento
     * sobre la cuarta parte de los puestos no dice lo mismo que sobre todos, y sin
     * este bloque las dos cosas se leen igual.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @param  array{sin_conciliar: int, nombres: array<string, array<string, mixed>>}  $base
     * @return array<string, mixed>
     */
    private function cobertura(ElectoralEvent $evento, array $filas, array $base): array
    {
        $sinActa = 0;
        $sinBase = 0;

        foreach ($filas as $fila) {
            if (! $fila['tiene_acta']) {
                $sinActa++;
            }

            if ($fila['base'] === 0) {
                $sinBase++;
            }
        }

        return [
            'puestos_sin_acta' => $sinActa,
            'puestos_sin_base' => $sinBase,
            // Actas leídas y cuadradas que no se pudieron colgar de ningún
            // puesto: sin `lugar` legible, o sin departamento con el que darlo de
            // alta. Sus votos no entran en ninguna fila.
            'actas_sin_conciliar' => E14Acta::query()
                ->where('electoral_event_id', $evento->id)
                ->where('estado', E14Acta::ESTADO_PROCESADA)
                ->whereNull('voting_place_id')
                ->count(),
            'base_sin_conciliar' => $base['sin_conciliar'],
            'nombres_sin_conciliar' => count($base['nombres']),
        ];
    }

    /**
     * `votos / base`, en porcentaje.
     *
     * El 100 % **no es un techo**: significa «igualaste tu base identificada». Por
     * debajo hay déficit; por encima, excedente —votos de gente que la campaña no
     * tenía en el sistema, que es lo normal.
     */
    private function rendimiento(int $votos, int $base): ?float
    {
        // Sin base no hay porcentaje que calcular. Devolver 0 diría «no rindo
        // nada» donde lo que pasa es que no se sabe.
        return $base > 0 ? round($votos / $base * 100, 2) : null;
    }

    private function contiene(?string $texto, string $buscado): bool
    {
        return str_contains(PuestoResolver::norm($texto), PuestoResolver::norm($buscado));
    }
}
