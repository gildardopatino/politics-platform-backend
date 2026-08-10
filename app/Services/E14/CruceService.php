<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use App\Models\E14Resultado;
use App\Models\ElectoralEvent;
use App\Models\Lead;
use App\Models\Voter;
use App\Models\VotingPlace;
use Illuminate\Validation\ValidationException;

/**
 * Potencial vs real: registrados contra los votos de mi candidato (Spec 0062).
 *
 * Es la pregunta que paga la analítica electoral: en este puesto tengo 340
 * personas registradas y mi candidato saco 120 votos — ¿dónde se fue el resto?
 *
 * Tres decisiones gobiernan el cálculo:
 *
 * 1. **Potencial = registrados**, no comprometidos ni encuestados: es el único
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

    /** Qué cuenta como «registrado». `voters` es el censo propio del tenant. */
    public const INCLUIR = ['voters', 'leads', 'ambos'];

    public function __construct(private readonly PuestoResolver $puestos) {}

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    public function calcular(ElectoralEvent $evento, array $filtros = []): array
    {
        if (! $evento->tieneCandidatoPropio()) {
            throw ValidationException::withMessages([
                'event' => 'Esta elección todavía no tiene candidato propio. '
                    .'Configúralo para poder cruzar los registrados con los votos reales.',
            ]);
        }

        $nivel = ($filtros['nivel'] ?? self::NIVEL_PUESTO) === self::NIVEL_MESA
            ? self::NIVEL_MESA
            : self::NIVEL_PUESTO;

        $incluir = in_array($filtros['incluir'] ?? 'voters', self::INCLUIR, true)
            ? $filtros['incluir'] ?? 'voters'
            : 'voters';

        // Las actas que se guardaron antes de que existiera el puesto canónico
        // —o antes de que su lugar estuviera en el catálogo— se resuelven aquí.
        // Es idempotente y evita una migración de datos que se quedaría corta con
        // la primera acta que llegue después.
        $this->puestos->conciliarActas($evento->id);

        $registrados = $this->registrados($nivel, $incluir);
        $actas = $this->actas($evento, $nivel);
        $votos = $this->votos($evento, $nivel);

        $filas = $this->armarFilas($nivel, $registrados['grupos'], $actas, $votos);
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
                'cobertura' => $this->cobertura($evento, $filas, $registrados),
            ],
        ];
    }

    /**
     * Registrados por puesto (y mesa), resolviendo a los que no tienen puesto.
     *
     * @return array{grupos: array<string, array<string, mixed>>, sin_conciliar: int, nombres: array<string, array<string, mixed>>}
     */
    private function registrados(string $nivel, string $incluir): array
    {
        $grupos = [];
        $sinConciliar = 0;
        $nombres = [];

        $acumular = function (?int $puesto, ?string $municipio, ?string $nombrePuesto, ?int $mesa, int $total) use (&$grupos, &$sinConciliar, &$nombres, $nivel): void {
            // El votante que no trae `voting_place_id` —captura vieja, o el
            // webhook— se mapea por nombre. Solo se busca: dar de alta un puesto
            // por lo que alguien escribió en un formulario llenaría el catálogo
            // global de basura.
            $puesto ??= $this->puestos->buscarPorNombre($municipio, $nombrePuesto);

            if ($puesto === null) {
                $sinConciliar += $total;

                $clave = PuestoResolver::clave($municipio, $nombrePuesto) ?? 'sin nombre';
                $nombres[$clave] ??= [
                    'municipio' => $municipio,
                    'puesto' => $nombrePuesto,
                    'registrados' => 0,
                ];
                $nombres[$clave]['registrados'] += $total;

                return;
            }

            $clave = $this->claveDeFila($puesto, $nivel === self::NIVEL_MESA ? $mesa : null);
            $grupos[$clave] ??= ['voting_place_id' => $puesto, 'mesa' => $nivel === self::NIVEL_MESA ? $mesa : null, 'registrados' => 0];
            $grupos[$clave]['registrados'] += $total;
        };

        if ($incluir !== 'leads') {
            $consulta = Voter::query()
                ->selectRaw('voting_place_id')
                ->selectRaw('municipio_votacion')
                ->selectRaw('puesto_votacion')
                ->selectRaw('COUNT(*) as total')
                ->groupBy('voting_place_id', 'municipio_votacion', 'puesto_votacion');

            if ($nivel === self::NIVEL_MESA) {
                $consulta->addSelect('mesa_votacion')->groupBy('mesa_votacion');
            }

            foreach ($consulta->get() as $fila) {
                $acumular(
                    $fila->voting_place_id !== null ? (int) $fila->voting_place_id : null,
                    $fila->municipio_votacion,
                    $fila->puesto_votacion,
                    PuestoResolver::mesa($fila->mesa_votacion ?? null),
                    (int) $fila->total,
                );
            }
        }

        // `leads` no tiene `voting_place_id`: son contactos sin la consulta de
        // Registraduría hecha, así que van siempre por nombre.
        if ($incluir !== 'voters') {
            $consulta = Lead::query()
                ->selectRaw('municipio_votacion')
                ->selectRaw('puesto_votacion')
                ->selectRaw('COUNT(*) as total')
                ->groupBy('municipio_votacion', 'puesto_votacion');

            if ($nivel === self::NIVEL_MESA) {
                $consulta->addSelect('mesa_votacion')->groupBy('mesa_votacion');
            }

            foreach ($consulta->get() as $fila) {
                $acumular(
                    null,
                    $fila->municipio_votacion,
                    $fila->puesto_votacion,
                    PuestoResolver::mesa($fila->mesa_votacion ?? null),
                    (int) $fila->total,
                );
            }
        }

        return ['grupos' => $grupos, 'sin_conciliar' => $sinConciliar, 'nombres' => $nombres];
    }

    /**
     * Las actas `procesada` que ya tienen puesto, por puesto (y mesa).
     *
     * Se cuentan aparte de los votos porque son dos preguntas distintas: «¿hay
     * acta de este puesto?» y «¿cuántos votos saco mi candidato ahí?». Un puesto
     * donde mi candidato saco cero votos **tiene** acta, y confundirlo con uno sin
     * escrutar sería leer un cero real como un dato que falta.
     *
     * @return array<string, int>
     */
    private function actas(ElectoralEvent $evento, string $nivel): array
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

        $conteo = [];

        foreach ($filas as $fila) {
            $clave = $this->claveDeFila(
                (int) $fila->voting_place_id,
                $nivel === self::NIVEL_MESA ? PuestoResolver::mesa($fila->mesa) : null
            );

            $conteo[$clave] = ($conteo[$clave] ?? 0) + (int) $fila->total;
        }

        return $conteo;
    }

    /**
     * Los votos de mi candidato, por puesto (y mesa).
     *
     * @return array<string, int>
     */
    private function votos(ElectoralEvent $evento, string $nivel): array
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

        $conteo = [];

        foreach ($filas as $fila) {
            $clave = $this->claveDeFila(
                (int) $fila->voting_place_id,
                $nivel === self::NIVEL_MESA ? PuestoResolver::mesa($fila->mesa) : null
            );

            $conteo[$clave] = ($conteo[$clave] ?? 0) + (int) $fila->votos;
        }

        return $conteo;
    }

    /**
     * Une los tres lados y le pone nombre a cada puesto.
     *
     * @param  array<string, array<string, mixed>>  $registrados
     * @param  array<string, int>  $actas
     * @param  array<string, int>  $votos
     * @return array<int, array<string, mixed>>
     */
    private function armarFilas(string $nivel, array $registrados, array $actas, array $votos): array
    {
        $filas = $registrados;

        // Un puesto con acta pero sin registrados también es una fila: es la
        // mitad más interesante de la cobertura (ahí hay votos de alguien más).
        foreach (array_keys($actas + $votos) as $clave) {
            if (isset($filas[$clave])) {
                continue;
            }

            [$puesto, $mesa] = $this->partirClave($clave);
            $filas[$clave] = ['voting_place_id' => $puesto, 'mesa' => $mesa, 'registrados' => 0];
        }

        $lugares = VotingPlace::query()
            ->whereIn('id', array_column($filas, 'voting_place_id'))
            ->get(['id', 'departamento_votacion', 'municipio_votacion', 'puesto_votacion'])
            ->keyBy('id');

        $armadas = [];

        foreach ($filas as $clave => $fila) {
            $lugar = $lugares->get($fila['voting_place_id']);
            $cuenta = $fila['registrados'];
            $misVotos = $votos[$clave] ?? 0;

            $armada = [
                'voting_place_id' => $fila['voting_place_id'],
                'departamento' => $lugar?->departamento_votacion,
                'municipio' => $lugar?->municipio_votacion,
                'puesto' => $lugar?->puesto_votacion,
                'registrados' => $cuenta,
                'votos_candidato' => $misVotos,
                'penetracion' => $this->penetracion($misVotos, $cuenta),
                'diferencia' => $misVotos - $cuenta,
                // Imposible legítimamente: nadie puede votar donde no está
                // registrado. Es un dato a revisar, no un rendimiento a celebrar.
                'anomalia' => $misVotos > $cuenta,
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
        $registrados = (int) array_sum(array_column($filas, 'registrados'));
        $votos = (int) array_sum(array_column($filas, 'votos_candidato'));

        return [
            'puestos' => count($filas),
            'registrados' => $registrados,
            'votos_candidato' => $votos,
            'penetracion' => $this->penetracion($votos, $registrados),
            'diferencia' => $votos - $registrados,
            'anomalias' => count(array_filter(array_column($filas, 'anomalia'))),
        ];
    }

    /**
     * Calidad del cruce. Es la mitad del valor del informe: un 80 % de
     * penetración sobre la cuarta parte de los puestos no dice lo mismo que sobre
     * todos, y sin este bloque las dos cosas se leen igual.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @param  array{sin_conciliar: int, nombres: array<string, array<string, mixed>>}  $registrados
     * @return array<string, mixed>
     */
    private function cobertura(ElectoralEvent $evento, array $filas, array $registrados): array
    {
        $sinActa = 0;
        $sinRegistrados = 0;

        foreach ($filas as $fila) {
            if (! $fila['tiene_acta']) {
                $sinActa++;
            }

            if ($fila['registrados'] === 0) {
                $sinRegistrados++;
            }
        }

        return [
            'puestos_sin_acta' => $sinActa,
            'puestos_sin_registrados' => $sinRegistrados,
            // Actas leídas y cuadradas que no se pudieron colgar de ningún
            // puesto: sin `lugar` legible, o sin departamento con el que darlo de
            // alta. Sus votos no entran en ninguna fila.
            'actas_sin_conciliar' => E14Acta::query()
                ->where('electoral_event_id', $evento->id)
                ->where('estado', E14Acta::ESTADO_PROCESADA)
                ->whereNull('voting_place_id')
                ->count(),
            'registrados_sin_conciliar' => $registrados['sin_conciliar'],
            'nombres_sin_conciliar' => count($registrados['nombres']),
        ];
    }

    private function penetracion(int $votos, int $registrados): ?float
    {
        // Sin registrados no hay porcentaje que calcular. Devolver 0 diría «no
        // penetro nada» donde lo que pasa es que no se sabe.
        return $registrados > 0 ? round($votos / $registrados * 100, 2) : null;
    }

    private function contiene(?string $texto, string $buscado): bool
    {
        return str_contains(PuestoResolver::norm($texto), PuestoResolver::norm($buscado));
    }

    private function claveDeFila(int $puesto, ?int $mesa): string
    {
        return $puesto.'|'.($mesa ?? '');
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    private function partirClave(string $clave): array
    {
        [$puesto, $mesa] = explode('|', $clave, 2);

        return [(int) $puesto, $mesa === '' ? null : (int) $mesa];
    }
}
