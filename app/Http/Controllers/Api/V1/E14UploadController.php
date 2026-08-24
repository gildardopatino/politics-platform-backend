<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\E14\UploadE14ActaRequest;
use App\Http\Resources\Api\V1\E14ActaResource;
use App\Models\E14Acta;
use App\Services\E14\E14ColaService;
use App\Services\E14\EventoResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Carga de actas y cola de procesamiento (Spec 0071).
 *
 * Es la mitad que mira al panel: subir los PDFs, dar la orden de procesarlos y
 * —desde la 0077— devolver a la cola un acta que hay que volver a leer. La otra
 * mitad, la que mira al worker, vive en `E14WorkerController`.
 */
class E14UploadController extends Controller
{
    public function __construct(
        private readonly E14ColaService $cola,
        private readonly EventoResolver $eventos,
    ) {}

    /**
     * Sube un acta y **la deja en la cola**. Deduplica por contenido: el mismo
     * PDF no se carga dos veces ni reabre una que ya se leyó.
     *
     * El tipo de elección ya no se pide (Spec 0093): lo pone la campaña. Una sin
     * elección —cargo `Otro`— no puede cargar, y se le dice por qué en vez de
     * dejarla subir PDFs a un escrutinio que no existe.
     */
    public function upload(UploadE14ActaRequest $request): JsonResponse
    {
        $lote = $request->input('upload_batch_id') ?: (string) Str::uuid();

        [$acta, $duplicada] = $this->cola->cargar(
            archivo: $request->file('archivo'),
            tipo: $this->eventos->tipoDeLaCampana($request->user()->tenant),
            tenantId: $request->user()->tenant_id,
            eventoId: $request->input('electoral_event_id') ? (int) $request->input('electoral_event_id') : null,
            batchId: $lote,
        );

        return response()->json([
            'data' => new E14ActaResource($acta->load('electoralEvent')),
            'upload_batch_id' => $lote,
            'duplicada' => $duplicada,
            'message' => $duplicada
                ? 'Ese archivo ya estaba cargado; se devuelve el acta existente.'
                : 'Acta cargada y encolada.',
        ], $duplicada ? 200 : 201);
    }

    /**
     * Reencola lo que se quedó en `cargada`.
     *
     * Desde la 0072 subir ya encola, así que esto es la salida de emergencia
     * para lo que entró antes de ese cambio o para un lote que alguien quiera
     * volver a mandar a la cola.
     */
    public function procesar(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'tipo' => ['nullable', Rule::in(E14Acta::TIPOS)],
            'batch_id' => 'nullable|uuid',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
        ]);

        $encoladas = $this->cola->encolar($filtros);

        return response()->json([
            'data' => ['encoladas' => $encoladas],
            'message' => $encoladas === 0
                ? 'No había actas cargadas por procesar.'
                : "Se encolaron {$encoladas} actas.",
        ]);
    }

    /**
     * Devuelve un acta ya leída a la cola, descartando su lectura (Spec 0077).
     *
     * Es la salida de la revisión cuando no hay nada que corregir a mano: el
     * lector no transcribió el acta y lo único útil es que la vuelva a leer.
     * Conserva el archivo; con el acta en vuelo responde 409.
     */
    public function reprocesar(E14Acta $acta): JsonResponse
    {
        $acta = $this->cola->reprocesar($acta);

        return response()->json([
            'data' => new E14ActaResource($acta->load('electoralEvent')),
            'message' => 'Acta devuelta a la cola: se descartó la lectura anterior y el worker la volverá a leer.',
        ]);
    }

    /**
     * Conteos por estado, tipo y lote. Es lo que el panel consulta para mostrar
     * el avance mientras el worker trabaja.
     */
    public function resumen(Request $request): JsonResponse
    {
        return response()->json($this->cola->resumen(
            $request->input('batch_id'),
            $request->input('tipo'),
        ));
    }
}
