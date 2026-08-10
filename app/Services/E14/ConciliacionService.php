<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use App\Models\E14PuestoAlias;
use App\Models\ElectoralEvent;
use App\Models\Voter;
use App\Models\VotingPlace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Conciliación de puestos: lo que la normalización no pudo unir (Spec 0062).
 *
 * El acta y la consulta de Registraduría salen de la misma fuente pero no
 * escriben igual: «COL. SAN SIMON» y «COLEGIO SAN SIMON» son el mismo colegio y
 * dos renglones distintos. Unirlos por parecido sería adivinar sobre el
 * escrutinio, así que lo que hace este servicio es **listar los sospechosos y
 * ejecutar la decisión de una persona**, nunca tomarla.
 *
 * Se listan dos cosas, que son las dos formas de quedarse sin pareja:
 *
 * - **del lado del acta**: puestos con acta y con cero registrados. Casi siempre
 *   es un renglón que creó la grafía del acta, y los votantes están en otro.
 * - **del lado del votante**: nombres de puesto que no resuelven a ningún renglón
 *   del catálogo.
 *
 * Lo que *no* se lista son los puestos con registrados y sin acta: esos no son un
 * problema de nombres, son actas que todavía no han llegado — y salen en la
 * cobertura del cruce. Meterlos aquí llenaría la pantalla de ruido la noche del
 * escrutinio, justo cuando tiene que servir para algo.
 */
class ConciliacionService
{
    /** Cuántos candidatos se ofrecen por cada nombre sin resolver. */
    private const MAX_SUGERENCIAS = 5;

    /** Palabras demasiado cortas para que compartirlas signifique algo. */
    private const MIN_LARGO_PALABRA = 4;

    public function __construct(
        private readonly RegistradosService $registrados,
        private readonly PuestoResolver $puestos,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function pendientes(ElectoralEvent $evento, string $incluir = 'voters'): array
    {
        // Igual que el cruce: primero se resuelve lo resoluble, para que la lista
        // sean de verdad los casos que necesitan a una persona.
        $this->puestos->conciliarActas($evento->id);

        $recuento = $this->registrados->contar(CruceService::NIVEL_PUESTO, $incluir);

        $porPuesto = [];

        foreach ($recuento['grupos'] as $grupo) {
            $porPuesto[$grupo['voting_place_id']] = $grupo['registrados'];
        }

        $conActa = $this->actasPorPuesto($evento);
        $lugares = $this->nombresDe(array_merge(array_keys($porPuesto), array_keys($conActa)));

        $conRegistrados = array_filter($porPuesto, fn (int $total) => $total > 0);

        $pendientes = [];

        // --- del lado del acta -------------------------------------------
        foreach ($conActa as $id => $actas) {
            if (($porPuesto[$id] ?? 0) > 0) {
                continue;
            }

            $lugar = $lugares->get($id);

            $pendientes[] = [
                'origen' => 'acta',
                'voting_place_id' => $id,
                'departamento' => $lugar?->departamento_votacion,
                'municipio' => $lugar?->municipio_votacion,
                'puesto' => $lugar?->puesto_votacion,
                'registrados' => 0,
                'actas' => $actas,
                'sugerencias' => $this->sugerencias(
                    $lugar?->municipio_votacion,
                    $lugar?->puesto_votacion,
                    array_keys($conRegistrados),
                    $lugares,
                    $porPuesto,
                    $conActa,
                ),
            ];
        }

        // --- del lado del votante ----------------------------------------
        foreach ($recuento['nombres'] as $nombre) {
            $pendientes[] = [
                'origen' => 'votante',
                'voting_place_id' => null,
                'departamento' => null,
                'municipio' => $nombre['municipio'],
                'puesto' => $nombre['puesto'],
                'registrados' => $nombre['registrados'],
                'actas' => 0,
                'sugerencias' => $this->sugerencias(
                    $nombre['municipio'],
                    $nombre['puesto'],
                    array_keys($conActa),
                    $lugares,
                    $porPuesto,
                    $conActa,
                ),
            ];
        }

        usort($pendientes, fn (array $a, array $b) => [$b['registrados'], $b['actas']]
            <=> [$a['registrados'], $a['actas']]);

        return [
            'data' => $pendientes,
            'meta' => [
                'electoral_event_id' => $evento->id,
                'incluir' => $incluir,
                'total' => count($pendientes),
                'registrados_sin_conciliar' => $recuento['sin_conciliar'],
                // Estas no se pueden fusionar: no tienen nombre de puesto que
                // unir. Se arreglan corrigiendo el acta (`PUT /actas/{id}`).
                'actas_sin_puesto' => E14Acta::query()
                    ->where('electoral_event_id', $evento->id)
                    ->where('estado', E14Acta::ESTADO_PROCESADA)
                    ->whereNull('voting_place_id')
                    ->count(),
                'fusiones' => E14PuestoAlias::query()->count(),
            ],
        ];
    }

    /**
     * Ejecuta la fusión que decidió una persona.
     *
     * El origen puede venir como un renglón del catálogo (`origenId`) o como un
     * nombre suelto que nunca llegó a tener renglón (`municipio` + `puesto`): son
     * los dos casos de la lista y los dos se resuelven igual, guardando que ese
     * **nombre** va a ese puesto.
     *
     * El catálogo global **no se toca**: `voting_places` es compartido entre
     * campañas y borrar un renglón cambiaría los datos de otros tenants. Lo que se
     * reapunta son las filas propias.
     *
     * @return array<string, mixed>
     */
    public function fusionar(
        int $tenantId,
        int $destinoId,
        ?int $origenId = null,
        ?string $municipio = null,
        ?string $puesto = null,
    ): array {
        return DB::transaction(function () use ($tenantId, $destinoId, $origenId, $municipio, $puesto) {
            $destino = VotingPlace::findOrFail($destinoId);
            $origen = $origenId === null ? null : VotingPlace::findOrFail($origenId);

            if ($origen !== null) {
                $municipio = $origen->municipio_votacion;
                $puesto = $origen->puesto_votacion;
            }

            $clave = PuestoResolver::clave($municipio, $puesto);

            if ($clave === null) {
                throw ValidationException::withMessages([
                    'puesto' => 'Hacen falta el municipio y el nombre del puesto que se quiere fusionar.',
                ]);
            }

            E14PuestoAlias::updateOrCreate(
                ['tenant_id' => $tenantId, 'clave' => $clave],
                [
                    'voting_place_id' => $destino->id,
                    'municipio' => $municipio,
                    'puesto' => $puesto,
                ],
            );

            $votantes = 0;
            $actas = 0;

            if ($origen !== null && $origen->id !== $destino->id) {
                // Las filas que ya apuntaban al renglón absorbido. `update()`
                // masivo a propósito: pueden ser miles y el rastro de la decisión
                // lo deja el alias, que sí está auditado.
                $votantes = Voter::where('voting_place_id', $origen->id)
                    ->update(['voting_place_id' => $destino->id]);

                $actas = E14Acta::where('voting_place_id', $origen->id)
                    ->update(['voting_place_id' => $destino->id]);

                // Fusionar A→B cuando ya existía C→A deja las tres en B, para que
                // nunca haya que seguir una cadena para saber dónde cuenta un
                // registro.
                E14PuestoAlias::where('voting_place_id', $origen->id)
                    ->update(['voting_place_id' => $destino->id]);
            }

            return [
                'voting_place_id' => $destino->id,
                'departamento' => $destino->departamento_votacion,
                'municipio' => $destino->municipio_votacion,
                'puesto' => $destino->puesto_votacion,
                'nombre_fusionado' => $puesto,
                'votantes_movidos' => $votantes,
                'actas_movidas' => $actas,
            ];
        });
    }

    /**
     * @return array<int, int> voting_place_id => actas procesadas
     */
    private function actasPorPuesto(ElectoralEvent $evento): array
    {
        return E14Acta::query()
            ->where('electoral_event_id', $evento->id)
            ->where('estado', E14Acta::ESTADO_PROCESADA)
            ->whereNotNull('voting_place_id')
            ->selectRaw('voting_place_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('voting_place_id')
            ->pluck('total', 'voting_place_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return \Illuminate\Support\Collection<int, VotingPlace>
     */
    private function nombresDe(array $ids)
    {
        return VotingPlace::query()
            ->whereIn('id', array_unique($ids))
            ->get(['id', 'departamento_votacion', 'municipio_votacion', 'puesto_votacion'])
            ->keyBy('id');
    }

    /**
     * Candidatos a los que fusionar un nombre huérfano.
     *
     * **Son sugerencias, no fusiones.** Se ofrecen los puestos del mismo
     * municipio que tienen lo que a este le falta —si le faltan registrados, los
     * que los tienen— ordenados por cuántas palabras largas comparten. Compartir
     * «SAN» no dice nada; compartir «COLEGIO SIMON» sí.
     *
     * @param  array<int, int>  $candidatos
     * @param  \Illuminate\Support\Collection<int, VotingPlace>  $lugares
     * @param  array<int, int>  $porPuesto
     * @param  array<int, int>  $conActa
     * @return array<int, array<string, mixed>>
     */
    private function sugerencias(
        ?string $municipio,
        ?string $puesto,
        array $candidatos,
        $lugares,
        array $porPuesto,
        array $conActa,
    ): array {
        if (blank($municipio) || blank($puesto)) {
            return [];
        }

        $municipioNorm = PuestoResolver::norm($municipio);
        $palabras = $this->palabras($puesto);
        $ofertas = [];

        foreach ($candidatos as $id) {
            $lugar = $lugares->get($id);

            if ($lugar === null || PuestoResolver::norm($lugar->municipio_votacion) !== $municipioNorm) {
                continue;
            }

            if (PuestoResolver::norm($lugar->puesto_votacion) === PuestoResolver::norm($puesto)) {
                continue;
            }

            $ofertas[] = [
                'voting_place_id' => $id,
                'municipio' => $lugar->municipio_votacion,
                'puesto' => $lugar->puesto_votacion,
                'registrados' => $porPuesto[$id] ?? 0,
                'actas' => $conActa[$id] ?? 0,
                'palabras_en_comun' => count(array_intersect($palabras, $this->palabras($lugar->puesto_votacion))),
            ];
        }

        usort($ofertas, fn (array $a, array $b) => [$b['palabras_en_comun'], $b['registrados']]
            <=> [$a['palabras_en_comun'], $a['registrados']]);

        return array_slice($ofertas, 0, self::MAX_SUGERENCIAS);
    }

    /**
     * @return array<int, string>
     */
    private function palabras(?string $texto): array
    {
        $palabras = preg_split('/\s+/', PuestoResolver::norm($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $palabras,
            fn (string $palabra) => mb_strlen($palabra) >= self::MIN_LARGO_PALABRA
        ));
    }
}
