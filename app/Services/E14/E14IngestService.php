<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use App\Models\E14Candidate;
use App\Models\E14Resultado;
use App\Models\ElectoralEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro de un acta (Spec 0061 · Parte A).
 *
 * Una mesa se escruta una vez, así que la operación es un upsert sobre la clave
 * natural (evento, tipo, zona, puesto, mesa) y no un `create`. El lector puede
 * volver a correr el mismo lote las veces que haga falta —y lo va a hacer, por
 * cortes de red o por cambiar de modelo de visión— sin duplicar un solo voto.
 *
 * El `estado` lo decide siempre `CuadreService` a partir de los números crudos.
 * Lo único que el cliente puede fijar es `revision_manual`, que es su forma de
 * decir «no pude leerla»: un acta ilegible no tiene cifras que revalidar y lo
 * que importa es que la mesa aparezca en la cola en vez de faltar.
 */
class E14IngestService
{
    /**
     * La ubicación que trae el encabezado impreso del acta (Spec 0074).
     *
     * Código **y** nombre de departamento y municipio, más el nombre del puesto.
     * Es metadato: se copia tal cual y no entra en el cuadre.
     */
    private const UBICACION = ['departamento_code', 'departamento', 'municipio_code', 'municipio', 'lugar'];

    public function __construct(
        private readonly CuadreService $cuadre,
        private readonly EventoResolver $eventos,
        private readonly PuestoResolver $puestos,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function registrar(array $datos, int $tenantId): E14Acta
    {
        return DB::transaction(function () use ($datos, $tenantId) {
            $evento = $this->eventos->resolver($datos, $tenantId);
            $resultados = $datos['resultados'] ?? [];

            $ilegible = ($datos['estado'] ?? null) === E14Acta::ESTADO_REVISION_MANUAL;

            $veredicto = $this->cuadre->evaluar(
                sumaCandidatos: (int) array_sum(array_column($resultados, 'votos')),
                votosBlanco: (int) ($datos['votos_blanco'] ?? 0),
                votosNulos: (int) ($datos['votos_nulos'] ?? 0),
                votosNoMarcados: (int) ($datos['votos_no_marcados'] ?? 0),
                sumaDeclarada: (int) ($datos['suma_declarada'] ?? 0),
                votosUrna: (int) ($datos['votos_urna'] ?? 0),
                votantesE11: (int) ($datos['votantes_e11'] ?? 0),
            );

            $clave = [
                'tenant_id' => $tenantId,
                'electoral_event_id' => $evento->id,
                'tipo' => $datos['tipo'],
                'zona' => $datos['zona'],
                'puesto' => $datos['puesto'],
                'mesa' => $datos['mesa'],
            ];

            $this->rechazarArchivoYaRadicado($datos, $tenantId, $clave);

            $acta = E14Acta::updateOrCreate($clave, [
                'departamento_code' => $datos['departamento_code'] ?? null,
                'departamento' => $datos['departamento'] ?? null,
                'municipio_code' => $datos['municipio_code'] ?? null,
                'municipio' => $datos['municipio'] ?? null,
                'lugar' => $datos['lugar'] ?? null,
                'archivo_nombre' => $datos['archivo_nombre'] ?? null,
                'archivo_hash' => $datos['archivo_hash'] ?? null,
                'estado' => $ilegible ? E14Acta::ESTADO_REVISION_MANUAL : $veredicto->estado,
                'suma_calculada' => $veredicto->sumaCalculada,
                'suma_declarada' => $veredicto->sumaDeclarada,
                'votos_urna' => $veredicto->votosUrna,
                'votantes_e11' => (int) ($datos['votantes_e11'] ?? 0),
                'dif_nivelacion' => $veredicto->difNivelacion,
                'votos_blanco' => (int) ($datos['votos_blanco'] ?? 0),
                'votos_nulos' => (int) ($datos['votos_nulos'] ?? 0),
                'votos_no_marcados' => (int) ($datos['votos_no_marcados'] ?? 0),
                'fuente' => $datos['fuente'] ?? E14Acta::FUENTE_VISION,
                'confianza' => $datos['confianza'] ?? null,
                'observacion' => $this->observacionDe($ilegible, $veredicto, $datos),
                'processed_at' => now(),
            ]);

            $this->aplicarConstancias($acta, $datos);
            $this->aplicarPuesto($acta);
            $acta->save();

            $this->sincronizarResultados($acta, $evento, $resultados);

            return $acta->load(['resultados.candidate', 'electoralEvent']);
        });
    }

    /**
     * Vuelve a evaluar el acta después de una corrección manual.
     *
     * @param  array<string, mixed>  $datos
     */
    public function corregir(E14Acta $acta, array $datos): E14Acta
    {
        return DB::transaction(function () use ($acta, $datos) {
            if (array_key_exists('resultados', $datos)) {
                $this->sincronizarResultados($acta, $acta->electoralEvent, $datos['resultados']);
                $acta->load('resultados');
            }

            foreach ([...self::UBICACION, 'observacion'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $acta->{$campo} = $datos[$campo];
                }
            }

            // Quien mira el papel suele descifrar la letra de los jurados mejor
            // que la visión, así que puede completar el bloque (Spec 0073).
            $this->aplicarConstancias($acta, $datos);

            // Corregir el nombre del puesto es lo que hace que un acta entre al
            // cruce (Spec 0062): el puesto canónico se recalcula con el nombre
            // corregido, no con el que leyó mal la visión.
            $this->aplicarPuesto($acta);

            foreach (['suma_declarada', 'votos_urna', 'votantes_e11', 'votos_blanco', 'votos_nulos', 'votos_no_marcados'] as $cifra) {
                if (array_key_exists($cifra, $datos)) {
                    $acta->{$cifra} = (int) $datos[$cifra];
                }
            }

            $veredicto = $this->cuadre->evaluar(
                sumaCandidatos: (int) $acta->resultados->sum('votos'),
                votosBlanco: (int) $acta->votos_blanco,
                votosNulos: (int) $acta->votos_nulos,
                votosNoMarcados: (int) $acta->votos_no_marcados,
                sumaDeclarada: (int) $acta->suma_declarada,
                votosUrna: (int) $acta->votos_urna,
                votantesE11: (int) $acta->votantes_e11,
            );

            $acta->fill([
                'estado' => $veredicto->estado,
                'suma_calculada' => $veredicto->sumaCalculada,
                'dif_nivelacion' => $veredicto->difNivelacion,
                'fuente' => E14Acta::FUENTE_MANUAL,
                'processed_at' => now(),
            ]);

            if ($veredicto->motivo !== '') {
                $acta->observacion = $veredicto->motivo;
            } elseif (! array_key_exists('observacion', $datos)) {
                $acta->observacion = null;
            }

            $acta->save();

            return $acta->load(['resultados.candidate', 'electoralEvent']);
        });
    }

    /**
     * Qué explica el estado guardado: el motivo del servidor o el del cliente.
     *
     * La regla general es que **manda el servidor**: si el lector dice que el
     * acta cuadra y las cifras dicen que no, quien explica el estado que se
     * guardó es el cuadre.
     *
     * La excepción es el acta que el propio cliente declara **ilegible**. Ahí no
     * hay discrepancia que resolver —los dos la mandan a revisión— y su
     * explicación es la útil: «la casilla del candidato 3 está tachada» dice
     * dónde mirar, mientras que el motivo genérico de «sin datos» (Spec 0077)
     * solo repite lo que ya se sabe. Es la misma preferencia que aplica
     * `registrarResultado()` por el camino del worker.
     *
     * @param  array<string, mixed>  $datos
     */
    private function observacionDe(bool $ilegible, Cuadre $veredicto, array $datos): ?string
    {
        if ($ilegible) {
            return ($datos['observacion'] ?? null) ?: ($veredicto->motivo ?: null);
        }

        return $veredicto->motivo !== ''
            ? $veredicto->motivo
            : ($datos['observacion'] ?? null);
    }

    /**
     * El mismo PDF archivado bajo dos mesas distintas es un error de radicación,
     * no una actualización: si se dejara pasar, los mismos votos entrarían dos
     * veces al consolidado.
     *
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $clave
     */
    private function rechazarArchivoYaRadicado(array $datos, int $tenantId, array $clave): void
    {
        $hash = $datos['archivo_hash'] ?? null;

        if (blank($hash)) {
            return;
        }

        $existente = E14Acta::where('tenant_id', $tenantId)
            ->where('archivo_hash', $hash)
            ->first();

        if (! $existente) {
            return;
        }

        $esLaMisma = $existente->electoral_event_id === $clave['electoral_event_id']
            && $existente->tipo === $clave['tipo']
            && $existente->zona === $clave['zona']
            && $existente->puesto === $clave['puesto']
            && $existente->mesa === $clave['mesa'];

        if ($esLaMisma) {
            return;
        }

        throw ValidationException::withMessages([
            'archivo_hash' => "Ese archivo ya está registrado en la mesa {$existente->mesa} "
                ."(zona {$existente->zona}, puesto {$existente->puesto}).",
        ]);
    }

    /**
     * Guarda lo que el worker leyó de un acta que ya existía en la cola
     * (Spec 0071).
     *
     * Se diferencia de `registrar()` en de dónde viene la identidad de la mesa:
     * allí el cliente la trae y el acta se crea o se actualiza a partir de ella;
     * aquí el acta ya existe —alguien subió su PDF— y lo que llega es de qué
     * mesa resultó ser. Por eso puede chocar con otra ya registrada.
     *
     * Idempotente: reenviar el mismo resultado deja lo mismo. Un worker que
     * publica y se cae antes de leer la respuesta puede repetir sin miedo.
     *
     * @param  array<string, mixed>  $datos
     */
    public function registrarResultado(E14Acta $acta, array $datos): E14Acta
    {
        return DB::transaction(function () use ($acta, $datos) {
            $ilegible = ($datos['estado'] ?? null) === E14Acta::ESTADO_REVISION_MANUAL;

            // Antes de cualquier bifurcación: las constancias se guardan pase lo
            // que pase (Spec 0073). Si las casillas no se dejaron leer pero el
            // bloque de la página 2 sí, ese texto es justo lo que quien revise
            // va a querer tener delante.
            $this->aplicarConstancias($acta, $datos);

            // Y la ubicación por la misma razón (Spec 0074): el encabezado va
            // impreso y se lee casi siempre; las que se tuercen son las casillas
            // manuscritas. Descartarlo dejaría al revisor sin saber siquiera de
            // qué puesto es el papel que tiene delante. Va sin zona/puesto/mesa:
            // esas son la clave única de la mesa y se deciden más abajo.
            $this->aplicarUbicacion($acta, $datos);

            // Y el puesto canónico con ella (Spec 0062): va antes de las dos
            // salidas de abajo porque un acta que se manda a revisión sigue
            // siendo de un puesto, y saber cuál es parte de lo que necesita
            // quien la revise.
            $this->aplicarPuesto($acta);

            if ($ilegible) {
                return $this->dejarEnRevision(
                    $acta,
                    ($datos['observacion'] ?? null) ?: 'el lector no pudo transcribir el acta'
                );
            }

            if ($otra = $this->mesaYaRegistradaEnOtraActa($acta, $datos)) {
                // Dos escaneos distintos de la misma mesa: los hashes no
                // coinciden, así que la deduplicación de la carga no los vio.
                // Decide una persona cuál vale; mientras tanto la cola sigue.
                //
                // La mesa leída **no se guarda**: escribirla chocaría con el
                // índice único, que es justo lo que está avisando. Va en el
                // motivo, que es donde alguien la va a leer.
                return $this->dejarEnRevision(
                    $acta,
                    "la mesa {$datos['mesa']} (zona {$datos['zona']}, puesto {$datos['puesto']}) ya está registrada en el acta #{$otra->id}"
                );
            }

            foreach (['zona', 'puesto', 'mesa'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $acta->{$campo} = $datos[$campo];
                }
            }

            $resultados = $datos['resultados'] ?? [];

            $veredicto = $this->cuadre->evaluar(
                sumaCandidatos: (int) array_sum(array_column($resultados, 'votos')),
                votosBlanco: (int) ($datos['votos_blanco'] ?? 0),
                votosNulos: (int) ($datos['votos_nulos'] ?? 0),
                votosNoMarcados: (int) ($datos['votos_no_marcados'] ?? 0),
                sumaDeclarada: (int) ($datos['suma_declarada'] ?? 0),
                votosUrna: (int) ($datos['votos_urna'] ?? 0),
                votantesE11: (int) ($datos['votantes_e11'] ?? 0),
            );

            $acta->fill([
                'estado' => $veredicto->estado,
                'suma_calculada' => $veredicto->sumaCalculada,
                'suma_declarada' => $veredicto->sumaDeclarada,
                'votos_urna' => $veredicto->votosUrna,
                'votantes_e11' => (int) ($datos['votantes_e11'] ?? 0),
                'dif_nivelacion' => $veredicto->difNivelacion,
                'votos_blanco' => (int) ($datos['votos_blanco'] ?? 0),
                'votos_nulos' => (int) ($datos['votos_nulos'] ?? 0),
                'votos_no_marcados' => (int) ($datos['votos_no_marcados'] ?? 0),
                'fuente' => $datos['fuente'] ?? E14Acta::FUENTE_VISION,
                'confianza' => $datos['confianza'] ?? null,
                'observacion' => $veredicto->motivo !== ''
                    ? $veredicto->motivo
                    : (($datos['observacion'] ?? null) ?: null),
                'processed_at' => now(),
                'claimed_at' => null,
            ]);

            $acta->save();

            $this->sincronizarResultados($acta, $acta->electoralEvent, $resultados);

            return $acta->load(['resultados.candidate', 'electoralEvent']);
        });
    }

    /**
     * Copia al acta el encabezado impreso: dónde está la mesa (Spec 0074).
     *
     * Se copia campo a campo y no con `fill()` para distinguir «no lo mandó» de
     * «lo mandó vacío»: quien corrige a mano tiene que poder borrar un nombre
     * mal transcrito, y una relectura que no trae el campo no debe pisarlo.
     *
     * @param  array<string, mixed>  $datos
     */
    private function aplicarUbicacion(E14Acta $acta, array $datos): void
    {
        foreach (self::UBICACION as $campo) {
            if (array_key_exists($campo, $datos)) {
                $acta->{$campo} = $datos[$campo];
            }
        }
    }

    /**
     * Resuelve el `lugar` impreso al puesto canónico del catálogo (Spec 0062).
     *
     * Es función de la ubicación que el acta tiene **en este momento**, así que se
     * recalcula siempre y no solo cuando el campo viene en la petición: una
     * corrección del nombre del puesto tiene que mover el acta de sitio en el
     * cruce.
     */
    private function aplicarPuesto(E14Acta $acta): void
    {
        // Registrar un acta es una operación suelta, así que empieza leyendo el
        // catálogo y las fusiones al día: si alguien acaba de fusionar dos
        // grafías, esta acta ya tiene que caer en la buena (ver
        // `PuestoResolver::refrescar()`).
        $this->puestos->refrescar();

        $acta->voting_place_id = $this->puestos->resolverActa($acta);
    }

    /**
     * Copia al acta lo que los jurados escribieron en la página 2 (Spec 0073).
     *
     * Son informativas: **no tocan el cuadre**. Un acta no cuadra menos porque
     * alguien explique por qué no cuadra, y confundir las dos cosas sería dejar
     * que un texto libre decidiera qué votos entran al consolidado.
     *
     * @param  array<string, mixed>  $datos
     */
    private function aplicarConstancias(E14Acta $acta, array $datos): void
    {
        foreach (['hubo_recuento', 'constancias', 'recuento_solicitado_por', 'recuento_representacion'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $acta->{$campo} = $datos[$campo];
            }
        }
    }

    private function dejarEnRevision(E14Acta $acta, string $motivo): E14Acta
    {
        $acta->fill([
            'estado' => E14Acta::ESTADO_REVISION_MANUAL,
            'observacion' => $motivo,
            'processed_at' => now(),
            'claimed_at' => null,
        ])->save();

        return $acta->load(['resultados.candidate', 'electoralEvent']);
    }

    /**
     * ¿Hay ya otra acta registrada para la mesa que se acaba de leer?
     *
     * @param  array<string, mixed>  $datos
     */
    private function mesaYaRegistradaEnOtraActa(E14Acta $acta, array $datos): ?E14Acta
    {
        if (blank($datos['zona'] ?? null) || blank($datos['puesto'] ?? null) || blank($datos['mesa'] ?? null)) {
            return null;
        }

        return E14Acta::where('electoral_event_id', $acta->electoral_event_id)
            ->where('tipo', $acta->tipo)
            ->where('zona', $datos['zona'])
            ->where('puesto', $datos['puesto'])
            ->where('mesa', $datos['mesa'])
            ->whereKeyNot($acta->id)
            ->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $resultados
     */
    private function sincronizarResultados(E14Acta $acta, ElectoralEvent $evento, array $resultados): void
    {
        $numeros = [];

        foreach ($resultados as $fila) {
            $candidato = $this->resolverCandidato($evento, $acta->tipo, $fila);
            $numeros[] = (int) $fila['numero'];

            E14Resultado::updateOrCreate(
                ['e14_acta_id' => $acta->id, 'numero' => (int) $fila['numero']],
                [
                    'tenant_id' => $acta->tenant_id,
                    'e14_candidate_id' => $candidato->id,
                    'nombre' => $fila['nombre'] ?? $candidato->nombre,
                    'votos' => (int) $fila['votos'],
                ],
            );
        }

        // Una relectura con menos candidatos no debe dejar votos huérfanos de la
        // lectura anterior sumando en el consolidado.
        $acta->resultados()->whereNotIn('numero', $numeros ?: [-1])->delete();
    }

    /**
     * @param  array<string, mixed>  $fila
     */
    private function resolverCandidato(ElectoralEvent $evento, string $cargo, array $fila): E14Candidate
    {
        return E14Candidate::firstOrCreate(
            [
                'electoral_event_id' => $evento->id,
                'cargo' => $cargo,
                'numero' => (int) $fila['numero'],
            ],
            [
                'tenant_id' => $evento->tenant_id,
                'nombre' => $fila['nombre'] ?? "Candidato {$fila['numero']}",
            ],
        );
    }
}
