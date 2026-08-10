<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * La cola de actas por leer (Spec 0071).
 *
 * Entre subir un PDF y tener un resultado hay ahora tres pasos y dos procesos
 * distintos: alguien carga, alguien da la orden de procesar, y un worker que
 * corre en otra parte va tomando de a una. La cola es lo que los une.
 *
 * El punto delicado es el **reclamo**: si dos workers toman la misma acta, se
 * gasta el doble en la API de visión y, peor, se escribe dos veces sobre la
 * misma fila. Ver `tomar()` para cómo se evita.
 */
class E14ColaService
{
    public function __construct(
        private readonly E14ArchivoService $archivos,
        private readonly EventoResolver $eventos,
    ) {}

    /**
     * Guarda un PDF y crea (o reencuentra) su acta.
     *
     * **Deduplica por contenido.** Si el mismo archivo ya se subió, se devuelve
     * el acta que ya existe sin tocarle el estado: subir dos veces la misma foto
     * —cosa que pasa todo el tiempo cuando varias personas cargan el mismo
     * lote— no puede convertirse en dos mesas ni reabrir una ya leída.
     *
     * @return array{0: E14Acta, 1: bool} [$acta, $duplicada]
     */
    public function cargar(
        UploadedFile $archivo,
        string $tipo,
        int $tenantId,
        ?int $eventoId = null,
        ?string $batchId = null,
    ): array {
        $hash = $this->archivos->hashDe($archivo);

        $existente = E14Acta::where('archivo_hash', $hash)->first();

        if ($existente) {
            return [$existente, true];
        }

        $evento = $this->eventos->resolver(
            ['tipo' => $tipo, 'electoral_event_id' => $eventoId],
            $tenantId,
        );

        $ruta = $this->archivos->guardar($archivo, $tenantId, $hash);

        $acta = E14Acta::create([
            'tenant_id' => $tenantId,
            'electoral_event_id' => $evento->id,
            'tipo' => $tipo,
            'archivo_nombre' => $archivo->getClientOriginalName(),
            'archivo_hash' => $hash,
            'archivo_path' => $ruta,
            'upload_batch_id' => $batchId,
            'estado' => E14Acta::ESTADO_CARGADA,
            'fuente' => E14Acta::FUENTE_VISION,
        ]);

        return [$acta, false];
    }

    /**
     * Pone en la cola las actas cargadas que encajen con el filtro.
     *
     * Cargar y encolar son dos gestos separados a propósito: subir 120 PDFs
     * lleva un rato y a media subida no hay nada que procesar todavía. La orden
     * la da la persona cuando termina.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function encolar(array $filtros): int
    {
        $query = E14Acta::where('estado', E14Acta::ESTADO_CARGADA)
            ->when(! empty($filtros['tipo']), fn (Builder $q) => $q->where('tipo', $filtros['tipo']))
            ->when(! empty($filtros['batch_id']), fn (Builder $q) => $q->where('upload_batch_id', $filtros['batch_id']))
            ->when(! empty($filtros['ids']), fn (Builder $q) => $q->whereIn('id', $filtros['ids']));

        return $query->update([
            'estado' => E14Acta::ESTADO_PENDIENTE,
            'updated_at' => now(),
        ]);
    }

    /**
     * Entrega la siguiente acta pendiente a un worker, o `null` si no hay.
     *
     * @param  array<int, string>  $tipos
     */
    public function reclamar(array $tipos = []): ?E14Acta
    {
        $this->reencolarColgadas();

        // Si otro worker se adelantó, se prueba con la siguiente en vez de
        // devolver «no hay nada»: el tope es para no girar en falso si la cola
        // se vacía mientras tanto.
        foreach (range(1, 5) as $ignorado) {
            $acta = DB::transaction(function () use ($tipos) {
                $candidata = E14Acta::where('estado', E14Acta::ESTADO_PENDIENTE)
                    ->when($tipos !== [], fn (Builder $q) => $q->whereIn('tipo', $tipos))
                    ->orderBy('id')
                    // En PostgreSQL bloquea la fila hasta el commit, así que dos
                    // workers simultáneos no llegan siquiera a mirar la misma.
                    ->lockForUpdate()
                    ->first();

                if (! $candidata) {
                    return false;
                }

                return $this->tomar($candidata) ? $candidata : null;
            });

            if ($acta === false) {
                return null;
            }

            if ($acta instanceof E14Acta) {
                return $acta;
            }
        }

        return null;
    }

    /**
     * Marca un acta como reclamada. Devuelve `false` si alguien se adelantó.
     *
     * La garantía no está en el `lockForUpdate` sino aquí: el `UPDATE` lleva
     * `WHERE estado = 'pendiente'`, así que es el motor quien decide el ganador
     * en una sola sentencia atómica. Dos workers con la misma acta en la mano
     * dan 1 y 0 filas afectadas, y esto vale igual en PostgreSQL que en SQLite.
     * El bloqueo de fila es la optimización que evita que lleguen a competir.
     */
    public function tomar(E14Acta $acta): bool
    {
        $tomadas = E14Acta::whereKey($acta->id)
            ->where('estado', E14Acta::ESTADO_PENDIENTE)
            ->update([
                'estado' => E14Acta::ESTADO_PROCESANDO,
                'claimed_at' => now(),
                'intentos' => DB::raw('intentos + 1'),
                'updated_at' => now(),
            ]);

        if ($tomadas === 0) {
            return false;
        }

        $acta->refresh();

        return true;
    }

    /**
     * Devuelve a la cola las actas que un worker reclamó y nunca terminó.
     *
     * Un worker que se cae a mitad de una lectura deja el acta en `procesando` y
     * sin dueño. Volver a leerla es barato —el resultado es idempotente— y
     * perderla en silencio no lo es. Con todo, hay un tope de intentos: un PDF
     * que tumba al worker no puede tumbarlo indefinidamente a costa del resto de
     * la cola, así que a la tercera va a revisión humana.
     */
    public function reencolarColgadas(): int
    {
        $limite = now()->subMinutes((int) config('e14.claim_timeout_minutes'));
        $maxIntentos = (int) config('e14.max_intentos');

        $colgadas = E14Acta::where('estado', E14Acta::ESTADO_PROCESANDO)
            ->where('claimed_at', '<', $limite)
            ->get();

        foreach ($colgadas as $acta) {
            if ($acta->intentos >= $maxIntentos) {
                $acta->forceFill([
                    'estado' => E14Acta::ESTADO_REVISION_MANUAL,
                    'observacion' => "el lector no consiguió terminar esta acta en {$maxIntentos} intentos",
                    'claimed_at' => null,
                ])->save();

                continue;
            }

            $acta->forceFill([
                'estado' => E14Acta::ESTADO_PENDIENTE,
                'claimed_at' => null,
            ])->save();
        }

        return $colgadas->count();
    }

    /**
     * Conteos para el panel: cuántas actas hay en cada estado, y lo mismo
     * abierto por tipo de elección y por lote de carga.
     *
     * @return array<string, mixed>
     */
    public function resumen(?string $batchId = null, ?string $tipo = null): array
    {
        $base = fn () => E14Acta::query()
            ->when($batchId, fn (Builder $q) => $q->where('upload_batch_id', $batchId))
            ->when($tipo, fn (Builder $q) => $q->where('tipo', $tipo));

        return [
            'data' => [
                'por_estado' => $this->conteo($base(), 'estado', E14Acta::ESTADOS),
                'por_tipo' => $base()
                    ->selectRaw('tipo, COUNT(*) as total')
                    ->groupBy('tipo')
                    ->pluck('total', 'tipo')
                    ->map(fn ($total) => (int) $total)
                    ->all(),
                'por_lote' => $base()
                    ->whereNotNull('upload_batch_id')
                    ->selectRaw('upload_batch_id, COUNT(*) as total')
                    ->groupBy('upload_batch_id')
                    ->pluck('total', 'upload_batch_id')
                    ->map(fn ($total) => (int) $total)
                    ->all(),
                'total' => $base()->count(),
                // Lo que le queda por hacer al worker. Es el número que mira
                // quien está esperando a que termine.
                'en_cola' => $base()->whereIn('estado', [
                    E14Acta::ESTADO_PENDIENTE,
                    E14Acta::ESTADO_PROCESANDO,
                ])->count(),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $claves
     * @return array<string, int>
     */
    private function conteo(Builder $query, string $columna, array $claves): array
    {
        $conteo = $query->selectRaw("{$columna}, COUNT(*) as total")
            ->groupBy($columna)
            ->pluck('total', $columna)
            ->all();

        $resultado = [];

        foreach ($claves as $clave) {
            $resultado[$clave] = (int) ($conteo[$clave] ?? 0);
        }

        return $resultado;
    }
}
