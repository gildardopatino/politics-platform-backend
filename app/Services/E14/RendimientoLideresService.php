<?php

namespace App\Services\E14;

use App\Models\ElectoralEvent;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\User;
use App\Models\Voter;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Scorecard operativo del líder (Spec 0063 · Parte A).
 *
 * ### Por qué aquí no hay «votos del líder»
 *
 * El roadmap pedía «rendimiento por líder (líder → leads → mesa → votos)», pero
 * el modelo real no ata votos a un líder y fingir que sí sería inventar el
 * número más importante del informe:
 *
 * - **Un líder no posee votantes ni leads.** Los `voters` son de la campaña; los
 *   `leads` son un diccionario de cédulas para autocompletar en reuniones.
 * - **Lo que un líder sí produce son reuniones** (`meetings.planner_user_id`) y,
 *   a través de ellas, asistentes que hacen check-in.
 * - **El voto es secreto y las mesas se comparten.** En una mesa mueven gente
 *   varios líderes, y el acta no dice de quién es cada voto. Repartirlos sería
 *   una regla de tres disfrazada de dato.
 *
 * Por eso el scorecard tiene dos mitades que no se mezclan:
 *
 * 1. **La actividad, que es cierta**: reuniones, asistentes, check-ins y cuántos
 *    de esos check-ins resuelven a alguien de quien sabemos dónde vota. Lo que no
 *    resuelve se **reporta** —es movilización cuya mesa no conocemos—, no se
 *    descarta en silencio para que el porcentaje se vea mejor.
 * 2. **El rendimiento de sus mesas, que es un proxy declarado**: el déficit de la
 *    0076 en las mesas donde vota su gente, ponderado por cuánta gente suya hay
 *    en cada una. Sirve para la pregunta «¿quién movilizó mucho en mesas que no
 *    rindieron?», y viene siempre con el aviso de que es un número compartido con
 *    los demás líderes que también mueven ahí.
 *
 * El proxy se lee **siempre por mesa** (`nivel_fijo`): a nivel de puesto la gente
 * de un líder se diluye entre todas las mesas del colegio y el número deja de
 * decir nada de él.
 */
class RendimientoLideresService
{
    /** Lo accionable arriba: quien más déficit ponderado tiene en sus mesas. */
    public const ORDEN_DEFICIT = 'deficit';

    /** Quien más gente movilizó, mirando solo la mitad cierta del informe. */
    public const ORDEN_ACTIVIDAD = 'actividad';

    public const ORDENES = [self::ORDEN_DEFICIT, self::ORDEN_ACTIVIDAD];

    /**
     * El texto que acompaña al proxy. Es fijo y va en `meta` a propósito: la
     * limitación viaja con el dato, no en la cabeza de quien lo lea.
     */
    public const AVISO_PROXY = 'El voto es secreto y las mesas se comparten entre líderes: '
        .'el voto no se atribuye a ningún líder. El rendimiento de sus mesas es un proxy '
        .'compartido con los demás líderes que también mueven gente ahí, no su cosecha.';

    public const AVISO_INFLADO = '«Posible inflado» es una señal a revisar, no una acusación: '
        .'marca a quien movilizó mucho en mesas que rindieron por debajo de la base identificada. '
        .'Puede ser base inflada, movilización que no se tradujo en votos, o una mesa difícil.';

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
            // El mismo mensaje que el cruce: es la misma configuración la que
            // falta, y dos redacciones distintas para la misma causa mandan al
            // usuario a buscar dos cosas.
            throw ValidationException::withMessages([
                'event' => 'Esta elección todavía no tiene candidato propio. '
                    .'Configúralo para poder cruzar tu base identificada con los votos reales.',
            ]);
        }

        $orden = in_array($filtros['order'] ?? null, self::ORDENES, true)
            ? $filtros['order']
            : self::ORDEN_DEFICIT;

        // Igual que el cruce: las actas que se guardaron sin puesto canónico se
        // resuelven al preguntar, no con una migración que se queda corta con la
        // siguiente acta que llegue.
        $this->puestos->conciliarActas($evento->id);

        // Las tres piezas de la 0076, por mesa y con la llave compartida. Son las
        // mismas consultas que alimentan el cruce: si aquí saliera otro número,
        // uno de los dos paneles estaría mintiendo.
        $base = $this->registrados->contar(CruceService::NIVEL_MESA, 'voters')['grupos'];
        $actas = $this->votos->actasProcesadas($evento, CruceService::NIVEL_MESA);
        $votos = $this->votos->deMiCandidato($evento, CruceService::NIVEL_MESA);

        $lideres = User::query()
            ->where('is_team_leader', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        $reuniones = $this->reuniones();
        $asistencia = $this->asistencia();
        $movilizacion = $this->movilizacion();

        $filas = [];
        $mesasSinBase = [];

        foreach ($lideres as $lider) {
            $presencia = $movilizacion['presencia'][$lider->id] ?? [];
            $checkins = $asistencia[$lider->id]['checkins'] ?? 0;
            $identificados = array_sum($presencia);

            $proxy = $this->proxy($presencia, $base, $actas, $votos);
            $mesasSinBase += $proxy['mesas_sin_base'];

            $filas[] = [
                'lider' => ['id' => $lider->id, 'nombre' => $lider->name],
                // --- la mitad cierta
                'reuniones' => $reuniones[$lider->id] ?? 0,
                'asistentes' => $asistencia[$lider->id]['asistentes'] ?? 0,
                'checkins' => $checkins,
                'movilizados_identificados' => $identificados,
                // Calidad del dato, no desempeño: de la gente que movilizó,
                // cuánta sabemos dónde vota. `null` sin check-ins, porque un 0 %
                // ahí diría que falló en algo que ni siquiera intentó.
                'identificacion' => $checkins > 0 ? round($identificados / $checkins * 100, 2) : null,
                'puestos_cubiertos' => $this->puestosDe($presencia),
                'mesas_cubiertas' => count($presencia),
                // --- la mitad que es un proxy
                'mesas_sin_acta' => $proxy['mesas_sin_acta'],
                'mesas_en_deficit' => $proxy['mesas_en_deficit'],
                'mesas_en_superavit' => $proxy['mesas_en_superavit'],
                'rendimiento_ponderado' => $proxy['rendimiento_ponderado'],
                'deficit_ponderado' => $proxy['deficit_ponderado'],
                'posible_inflado' => $this->posibleInflado($identificados, $proxy['deficit_ponderado']),
            ];
        }

        $filas = $this->ordenar($filas, $orden);

        return [
            'data' => $filas,
            'meta' => [
                'electoral_event_id' => $evento->id,
                // No es un filtro: el proxy solo tiene sentido por mesa.
                'nivel_fijo' => CruceService::NIVEL_MESA,
                'orden' => $orden,
                // La misma forma que el cruce, con lista + `es_corporacion`
                // (Spec 0083): los dos paneles rotulan al mismo candidato.
                'candidato' => $evento->resumenDelCandidato(),
                'totales' => [
                    'lideres' => count($filas),
                    'reuniones' => (int) array_sum(array_column($filas, 'reuniones')),
                    'asistentes' => (int) array_sum(array_column($filas, 'asistentes')),
                    'checkins' => (int) array_sum(array_column($filas, 'checkins')),
                    // Personas distintas de toda la campaña. La suma de la
                    // columna puede ser mayor: quien asiste a reuniones de dos
                    // líderes cuenta para los dos, y ninguno de los dos miente.
                    'movilizados_identificados' => count($movilizacion['personas']),
                    'movilizados_por_lider' => (int) array_sum(array_column($filas, 'movilizados_identificados')),
                ],
                'umbrales' => [
                    'movilizados_identificados' => $this->umbralMovilizados(),
                    'deficit_ponderado' => $this->umbralDeficit(),
                ],
                'cobertura' => [
                    // Movilización cuya mesa no conocemos. Se reporta porque es
                    // trabajo hecho, no un cero.
                    'checkins_sin_identificar' => $movilizacion['sin_identificar'],
                    'lideres_sin_proxy' => count(array_filter(
                        $filas,
                        fn (array $fila) => $fila['rendimiento_ponderado'] === null
                    )),
                    'mesas_con_gente' => count($movilizacion['mesas']),
                    'mesas_sin_base' => count($mesasSinBase),
                ],
                'aviso_proxy' => self::AVISO_PROXY,
                'aviso_inflado' => self::AVISO_INFLADO,
            ],
        ];
    }

    /**
     * Reuniones que cada líder **planeó**.
     *
     * El MVP mide al planificador, no al subárbol de `reports_to`: sumar el
     * equipo de un coordinador es otra pregunta y merece decidirse aparte.
     *
     * @return array<int, int>
     */
    private function reuniones(): array
    {
        return Meeting::query()
            ->whereNotNull('planner_user_id')
            ->selectRaw('planner_user_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('planner_user_id')
            ->pluck('total', 'planner_user_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Asistentes y check-ins de esas reuniones, en una sola agregación.
     *
     * @return array<int, array{asistentes: int, checkins: int}>
     */
    private function asistencia(): array
    {
        $filas = $this->deLasReuniones()
            ->selectRaw('meetings.planner_user_id as lider')
            ->selectRaw('COUNT(*) as asistentes')
            ->selectRaw('SUM(CASE WHEN meeting_attendees.checked_in = ? THEN 1 ELSE 0 END) as checkins', [true])
            ->groupBy('meetings.planner_user_id')
            ->get();

        $porLider = [];

        foreach ($filas as $fila) {
            $porLider[(int) $fila->lider] = [
                'asistentes' => (int) $fila->asistentes,
                'checkins' => (int) $fila->checkins,
            ];
        }

        return $porLider;
    }

    /**
     * Quién movilizó a quién y a qué mesa, deduplicado por persona.
     *
     * Los check-ins son **eventos** y las personas son personas: quien asiste a
     * dos reuniones del mismo líder movilizó una vez, no dos. Por eso el mapa va
     * por votante y la presencia se cuenta después.
     *
     * @return array{
     *     presencia: array<int, array<string, int>>,
     *     personas: array<int, true>,
     *     mesas: array<string, true>,
     *     sin_identificar: int
     * }
     */
    private function movilizacion(): array
    {
        $checkins = $this->deLasReuniones()
            ->where('meeting_attendees.checked_in', true)
            ->selectRaw('meetings.planner_user_id as lider')
            ->selectRaw('meeting_attendees.voter_id as voter_id')
            ->selectRaw('meeting_attendees.cedula as cedula')
            ->get();

        [$porId, $porCedula] = $this->votantesDe($checkins);

        $porLider = [];
        $personas = [];
        $mesas = [];
        $sinIdentificar = 0;

        foreach ($checkins as $fila) {
            $lider = (int) $fila->lider;

            // `voter_id` primero (Spec 0022) y la cédula como respaldo: la
            // asistencia anterior a esa spec solo dejó el número.
            $votante = ($fila->voter_id !== null ? $porId[(int) $fila->voter_id] ?? null : null)
                ?? $porCedula[trim((string) $fila->cedula)]
                ?? null;

            $clave = $this->mesaDelVotante($votante);

            if ($clave === null) {
                $sinIdentificar++;

                continue;
            }

            // Una persona, una vez. `voters` es único por cédula dentro del
            // tenant, así que su id **es** la cédula a efectos de contarla.
            if (isset($porLider[$lider][$votante->id])) {
                continue;
            }

            $porLider[$lider][$votante->id] = $clave;
            $personas[$votante->id] = true;
            $mesas[$clave] = true;
        }

        $presencia = [];

        foreach ($porLider as $lider => $porPersona) {
            foreach ($porPersona as $clave) {
                $presencia[$lider][$clave] = ($presencia[$lider][$clave] ?? 0) + 1;
            }
        }

        return [
            'presencia' => $presencia,
            'personas' => $personas,
            'mesas' => $mesas,
            'sin_identificar' => $sinIdentificar,
        ];
    }

    /**
     * Los check-ins de las reuniones que planeó alguien, ya acotados al tenant.
     *
     * El `join` repite `tenant_id` en la condición además del `TenantScope` del
     * modelo: con dos tablas en juego, que la de la izquierda esté acotada no
     * dice nada de la de la derecha (Art. III).
     */
    private function deLasReuniones(): \Illuminate\Database\Eloquent\Builder
    {
        return MeetingAttendee::query()
            ->join('meetings', function (JoinClause $join) {
                $join->on('meetings.id', '=', 'meeting_attendees.meeting_id')
                    ->on('meetings.tenant_id', '=', 'meeting_attendees.tenant_id');
            })
            // Una reunión borrada no cuenta como trabajo hecho.
            ->whereNull('meetings.deleted_at')
            ->whereNotNull('meetings.planner_user_id');
    }

    /**
     * Los votantes detrás de esos check-ins, en **una** consulta.
     *
     * Resolver uno por uno sería el N+1 que la spec prohíbe; traer todos los
     * `voters` del tenant, memoria que no hace falta. El intermedio es pedir
     * exactamente los que aparecen en la movilización.
     *
     * @param  Collection<int, object>  $checkins
     * @return array{0: array<int, Voter>, 1: array<string, Voter>}
     */
    private function votantesDe(Collection $checkins): array
    {
        $ids = array_values(array_unique(array_filter($checkins->pluck('voter_id')->all())));
        $cedulas = array_values(array_unique(array_filter(
            array_map(fn ($cedula) => trim((string) $cedula), $checkins->pluck('cedula')->all()),
            fn (string $cedula) => $cedula !== '',
        )));

        if ($ids === [] && $cedulas === []) {
            return [[], []];
        }

        $votantes = Voter::query()
            ->where(function ($consulta) use ($ids, $cedulas) {
                if ($ids !== []) {
                    $consulta->orWhereIn('id', $ids);
                }

                if ($cedulas !== []) {
                    $consulta->orWhereIn('cedula', $cedulas);
                }
            })
            ->get(['id', 'cedula', 'voting_place_id', 'municipio_votacion', 'puesto_votacion', 'mesa_votacion']);

        $porId = [];
        $porCedula = [];

        foreach ($votantes as $votante) {
            $porId[$votante->id] = $votante;
            $porCedula[trim((string) $votante->cedula)] = $votante;
        }

        return [$porId, $porCedula];
    }

    /**
     * En qué mesa vota esa persona, o `null` si no se sabe.
     *
     * Sin puesto canónico se intenta el resolver de respaldo por nombre (0062),
     * que solo busca y nunca da de alta. Sin mesa tampoco hay proxy: el
     * rendimiento se lee por mesa y una mesa que no se conoce no se inventa.
     * Quien cae aquí sigue contando como check-in —movilizó—; lo que no se sabe
     * es dónde vota.
     */
    private function mesaDelVotante(?Voter $votante): ?string
    {
        if ($votante === null) {
            return null;
        }

        $puesto = $votante->voting_place_id !== null
            ? (int) $votante->voting_place_id
            : $this->puestos->buscarPorNombre($votante->municipio_votacion, $votante->puesto_votacion);

        $mesa = PuestoResolver::mesa($votante->mesa_votacion);

        return $puesto === null || $mesa === null
            ? null
            : PuestoResolver::claveMesa($puesto, $mesa);
    }

    /**
     * El déficit de la 0076 en las mesas del líder, ponderado por su presencia.
     *
     * Ponderar por presencia y no promediar a secas es lo que hace comparable el
     * número: una mesa donde el líder puso 40 personas dice mucho más de su
     * trabajo que una donde puso una. Lo que **no** hace es repartir votos —el
     * resultado es un porcentaje de rendimiento de esas mesas, compartido con
     * todos los que mueven ahí.
     *
     * Una mesa sin acta procesada no entra: sin escrutinio no hay con qué
     * comparar, y un 0 % diría que rindió mal cuando lo que pasa es que todavía
     * no se sabe.
     *
     * @param  array<string, int>  $presencia
     * @param  array<string, array<string, mixed>>  $base
     * @param  array<string, int>  $actas
     * @param  array<string, int>  $votos
     * @return array<string, mixed>
     */
    private function proxy(array $presencia, array $base, array $actas, array $votos): array
    {
        $sinActa = 0;
        $enDeficit = 0;
        $enSuperavit = 0;
        $sinBase = [];
        $peso = 0;
        $acumulado = 0.0;

        foreach ($presencia as $clave => $gente) {
            if (($actas[$clave] ?? 0) === 0) {
                $sinActa++;

                continue;
            }

            $baseMesa = (int) ($base[$clave]['base'] ?? 0);
            $votosMesa = $votos[$clave] ?? 0;

            if ($baseMesa > $votosMesa) {
                $enDeficit++;
            }

            if ($votosMesa > $baseMesa) {
                $enSuperavit++;
            }

            if ($baseMesa === 0) {
                $sinBase[$clave] = true;

                continue;
            }

            $peso += $gente;
            $acumulado += $votosMesa / $baseMesa * 100 * $gente;
        }

        $rendimiento = $peso > 0 ? round($acumulado / $peso, 2) : null;

        return [
            'mesas_sin_acta' => $sinActa,
            'mesas_sin_base' => $sinBase,
            'mesas_en_deficit' => $enDeficit,
            'mesas_en_superavit' => $enSuperavit,
            'rendimiento_ponderado' => $rendimiento,
            // Los puntos porcentuales de su base que no se reflejaron en votos.
            // Es la misma cifra vuelta del derecho, para que ordenar por «lo
            // accionable primero» no obligue a invertir el criterio.
            'deficit_ponderado' => $rendimiento === null ? null : round(max(0, 100 - $rendimiento), 2),
        ];
    }

    /**
     * La señal derivada: mucha movilización donde las mesas no rindieron.
     *
     * Las dos condiciones van juntas a propósito. Un líder con dos personas en
     * una mesa mala no tiene una base inflada, tiene dos personas; y un líder con
     * cien personas en mesas que rindieron no tiene nada que explicar. Los
     * umbrales viven en `config/e14.php` porque son un parámetro revisable, no un
     * veredicto: quien los cambie está cambiando a quién mira primero, no quién
     * hizo trampa.
     */
    private function posibleInflado(int $identificados, ?float $deficitPonderado): bool
    {
        if ($deficitPonderado === null) {
            return false;
        }

        return $identificados >= $this->umbralMovilizados()
            && $deficitPonderado >= $this->umbralDeficit();
    }

    private function umbralMovilizados(): int
    {
        return (int) config('e14.rendimiento_lideres.umbral_movilizados');
    }

    private function umbralDeficit(): float
    {
        return (float) config('e14.rendimiento_lideres.umbral_deficit_ponderado');
    }

    /**
     * @param  array<string, int>  $presencia
     */
    private function puestosDe(array $presencia): int
    {
        $puestos = [];

        foreach (array_keys($presencia) as $clave) {
            [$puesto] = PuestoResolver::partirClaveMesa($clave);
            $puestos[$puesto] = true;
        }

        return count($puestos);
    }

    /**
     * Por defecto, arriba lo accionable: el mayor déficit ponderado.
     *
     * Quien no tiene proxy —sin movilización identificada o sin actas todavía—
     * va al final y no se cuela entre los que sí tienen algo que mirar.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, array<string, mixed>>
     */
    private function ordenar(array $filas, string $orden): array
    {
        $peso = $orden === self::ORDEN_ACTIVIDAD
            ? fn (array $fila) => [$fila['movilizados_identificados'], $fila['checkins'], $fila['reuniones']]
            : fn (array $fila) => [$fila['deficit_ponderado'] ?? -1, $fila['movilizados_identificados']];

        usort($filas, fn (array $a, array $b) => $peso($b) <=> $peso($a)
            ?: $a['lider']['nombre'] <=> $b['lider']['nombre']);

        return $filas;
    }
}
