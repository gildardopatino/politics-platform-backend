<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    /**
     * Desde dónde se puede pedir una relectura (Spec 0077).
     *
     * Son los estados en los que el acta **no está en manos de nadie**: ya se
     * leyó —bien, mal o sin poder— o se quedó cargada sin encolar. Los dos que
     * faltan, `pendiente` y `procesando`, son justamente los de la cola en
     * marcha; ver `reprocesar()`.
     */
    public const REPROCESABLES = [
        E14Acta::ESTADO_CARGADA,
        E14Acta::ESTADO_PROCESADA,
        E14Acta::ESTADO_INCONSISTENTE,
        E14Acta::ESTADO_REVISION_MANUAL,
    ];

    /**
     * Lo que se borra al pedir una relectura: todo lo que salió de la lectura
     * anterior. Las cifras vuelven a 0 —son columnas no nulas con ese
     * defecto— y los metadatos de lectura a null.
     *
     * Fuera de esta lista queda lo que **identifica** al acta y no depende de
     * quién la leyó: archivo, tipo, evento, ubicación y las constancias de los
     * jurados. Si el archivo se fuera, no habría nada que volver a leer.
     */
    private const CIFRAS_LEIDAS = [
        'suma_calculada', 'suma_declarada', 'votos_urna', 'votantes_e11',
        'dif_nivelacion', 'votos_blanco', 'votos_nulos', 'votos_no_marcados',
    ];

    public function __construct(
        private readonly E14ArchivoService $archivos,
        private readonly EventoResolver $eventos,
    ) {}

    /**
     * Guarda un PDF y crea (o reencuentra) su acta, **ya encolada**.
     *
     * Cargar es encolar (Spec 0072). Antes el acta nacía `cargada` y alguien
     * tenía que pulsar «Procesar» después; quien subía un lote y se iba de la
     * pantalla dejaba las actas ahí, sin que nada avisara y sin un botón a mano
     * para arreglarlo. Un flujo que permite olvidarse de un paso no es un flujo
     * con un paso de más: es un defecto.
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
            'estado' => E14Acta::ESTADO_PENDIENTE,
            'fuente' => E14Acta::FUENTE_VISION,
        ]);

        return [$acta, false];
    }

    /**
     * Pone en la cola las actas que se quedaron en `cargada`.
     *
     * Desde la 0072 el camino normal no pasa por aquí: subir ya encola. Queda
     * para los casos de borde —actas cargadas antes de ese cambio, o un lote que
     * alguien quiera reencolar a mano—, porque quitarlo dejaría sin rescate lo
     * que ya estaba a medias.
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
     * Devuelve un acta a la cola y **descarta la lectura anterior** (Spec 0077).
     *
     * Es la salida que faltaba en la revisión: cuando el lector no consiguió
     * transcribir el acta no hay una casilla que corregir —está todo vacío—, y
     * lo único útil es que la vuelva a leer. También sirve cuando el modelo de
     * visión mejoró desde el primer intento.
     *
     * **Se rechaza si el acta está en vuelo** (`pendiente` o `procesando`). Con
     * `procesando` es evidente: un worker la tiene y reencolarla mientras trabaja
     * significa dos lecturas escribiendo sobre la misma fila. Con `pendiente` no
     * hay peligro, pero tampoco hay nada que hacer: ya está en la cola, y decir
     * que sí sería fingir un efecto. Ese rechazo es, de paso, lo que hace que
     * pulsar dos veces no encole dos veces.
     *
     * Los `intentos` vuelven a cero a propósito: un acta que ya falló dos veces
     * agotaría el tope en la primera relectura y volvería a revisión sola, sin
     * haber tenido su oportunidad.
     */
    public function reprocesar(E14Acta $acta): E14Acta
    {
        if (! in_array($acta->estado, self::REPROCESABLES, true)) {
            throw ValidationException::withMessages([
                'estado' => $acta->estado === E14Acta::ESTADO_PROCESANDO
                    ? 'Un worker está leyendo esta acta ahora mismo. Espera a que termine para volver a procesarla.'
                    : 'Esta acta ya está en la cola esperando a que un worker la lea.',
            ])->status(409);
        }

        return DB::transaction(function () use ($acta) {
            // Explícito y no por cascada de la FK: que los resultados se vayan
            // con el acta no puede depender de si el motor tiene las claves
            // foráneas activadas.
            $acta->resultados()->delete();

            $acta->forceFill([
                ...array_fill_keys(self::CIFRAS_LEIDAS, 0),
                'estado' => E14Acta::ESTADO_PENDIENTE,
                'intentos' => 0,
                'confianza' => null,
                'observacion' => null,
                'claimed_at' => null,
                'processed_at' => null,
                // La va a leer la visión otra vez, aunque la corrección
                // anterior fuera manual.
                'fuente' => E14Acta::FUENTE_VISION,
            ])->save();

            return $acta->refresh();
        });
    }

    /**
     * Borra el acta, sus resultados y su archivo (Spec 0077).
     *
     * La otra salida de la revisión: cuando el escaneo es ilegible, releer el
     * mismo archivo no lo va a arreglar y lo que hace falta es **volver a
     * cargarlo** mejor. Eso choca con la deduplicación por contenido de la 0072,
     * que devolvería el acta existente en vez de crear una nueva — y por eso
     * borrar la fila es lo que libera el `archivo_hash` (índice único
     * `tenant_id + archivo_hash`) y deja entrar de nuevo al mismo PDF.
     *
     * Se permite en cualquier estado, `procesando` incluido: quitar un acta no
     * puede depender de que un worker termine. Si su resultado llega después,
     * el acta ya no existe y recibe un 404, que es el contrato que el worker ya
     * tolera.
     */
    public function eliminar(E14Acta $acta): void
    {
        DB::transaction(function () use ($acta) {
            $acta->resultados()->delete();
            $acta->delete();
        });

        // Después del commit y a propósito: borrar un objeto del disco no se
        // deshace con un rollback. Es best-effort —un archivo que ya no está no
        // puede dejar la fila colgada para siempre—, y como el nombre en disco
        // es el hash del contenido bajo la carpeta del tenant, no hay otra acta
        // de esta campaña que lo comparta.
        $this->archivos->borrar($acta);
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
