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
    public function __construct(
        private readonly CuadreService $cuadre,
        private readonly EventoResolver $eventos,
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
                'municipio_code' => $datos['municipio_code'] ?? null,
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
                // El motivo del servidor manda sobre el del cliente: si
                // discrepan, el que explica el estado guardado es este.
                'observacion' => $veredicto->motivo !== ''
                    ? $veredicto->motivo
                    : ($datos['observacion'] ?? null),
                'processed_at' => now(),
            ]);

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

            foreach (['departamento_code', 'municipio_code', 'lugar', 'observacion'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $acta->{$campo} = $datos[$campo];
                }
            }

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
