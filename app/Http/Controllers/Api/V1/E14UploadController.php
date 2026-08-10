<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\E14\UploadE14ActaRequest;
use App\Http\Resources\Api\V1\E14ActaResource;
use App\Models\E14Acta;
use App\Services\E14\E14ColaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Carga de actas y cola de procesamiento (Spec 0071).
 *
 * Es la mitad que mira al panel: subir los PDFs y dar la orden de procesarlos.
 * La otra mitad —la que mira al worker— vive en `E14WorkerController`.
 */
class E14UploadController extends Controller
{
    public function __construct(private readonly E14ColaService $cola) {}

    /**
     * Sube un acta. Deduplica por contenido: el mismo PDF no se carga dos veces.
     */
    public function upload(UploadE14ActaRequest $request): JsonResponse
    {
        $lote = $request->input('upload_batch_id') ?: (string) Str::uuid();

        [$acta, $duplicada] = $this->cola->cargar(
            archivo: $request->file('archivo'),
            tipo: $request->input('tipo'),
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
                : 'Acta cargada.',
        ], $duplicada ? 200 : 201);
    }

    /**
     * Encola las actas cargadas: `cargada → pendiente`.
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
