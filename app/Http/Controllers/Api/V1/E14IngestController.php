<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\E14\StoreE14ActaRequest;
use App\Http\Requests\Api\V1\E14\UpdateE14ActaRequest;
use App\Http\Resources\Api\V1\E14ActaResource;
use App\Models\E14Acta;
use App\Services\E14\ConsolidadoService;
use App\Services\E14\E14ArchivoService;
use App\Services\E14\E14IngestService;
use App\Support\DatabaseExpressions;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ingesta y consulta del escrutinio E-14 (Spec 0061 · Parte A).
 *
 * La escribe el lector de actas (token de servicio) y la lee el panel (JWT).
 * Ambos entran por `e14.auth`, así que el tenant y los permisos se resuelven
 * igual para los dos.
 */
class E14IngestController extends Controller
{
    public function __construct(
        private readonly E14IngestService $ingesta,
        private readonly ConsolidadoService $consolidado,
        private readonly E14ArchivoService $archivos,
    ) {}

    /**
     * Registra un acta. Idempotente por mesa: reenviarla actualiza la fila.
     */
    public function store(StoreE14ActaRequest $request): JsonResponse
    {
        $existia = $this->yaEstaba($request);

        $acta = $this->ingesta->registrar($request->validated(), $request->user()->tenant_id);

        return response()->json(
            [
                'data' => new E14ActaResource($acta),
                'message' => $acta->cuadra()
                    ? 'Acta registrada.'
                    : 'Acta registrada con novedades: '.($acta->observacion ?: 'requiere revisión manual.'),
            ],
            $existia ? 200 : 201,
        );
    }

    public function index(Request $request): JsonResponse
    {
        $actas = E14Acta::query()
            ->with('electoralEvent')
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->input('estado')))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->input('tipo')))
            ->when($request->filled('zona'), fn ($q) => $q->where('zona', $request->input('zona')))
            ->when($request->filled('puesto'), fn ($q) => $q->where('puesto', $request->input('puesto')))
            ->when($request->filled('mesa'), fn ($q) => $q->where('mesa', $request->input('mesa')))
            ->tap(fn ($q) => $this->filtrarPorUbicacion($q, $request))
            ->when(
                $request->filled('electoral_event_id'),
                fn ($q) => $q->where('electoral_event_id', $request->input('electoral_event_id'))
            )
            ->orderBy('zona')
            ->orderBy('puesto')
            ->orderBy('mesa')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'data' => E14ActaResource::collection($actas->items()),
            'meta' => [
                'total' => $actas->total(),
                'current_page' => $actas->currentPage(),
                'last_page' => $actas->lastPage(),
                'per_page' => $actas->perPage(),
            ],
        ]);
    }

    /**
     * El detalle de un acta, con la URL firmada de su PDF si lo tiene.
     *
     * La URL se emite aquí y no se guarda: quien revisa un acta a mano necesita
     * mirar el papel, y una URL firmada guardada es una URL que caduca en la
     * base de datos (Spec 0071).
     */
    public function show(E14Acta $acta): JsonResponse
    {
        $acta->load(['resultados.candidate', 'electoralEvent']);

        return response()->json([
            'data' => new E14ActaResource($acta),
            'archivo_url' => $this->archivos->urlFirmada($acta),
        ]);
    }

    /**
     * Corrección manual. Deja `fuente=manual` y vuelve a evaluar el cuadre.
     */
    public function update(UpdateE14ActaRequest $request, E14Acta $acta): JsonResponse
    {
        $acta = $this->ingesta->corregir($acta->load('resultados'), $request->validated());

        return response()->json([
            'data' => new E14ActaResource($acta),
            'message' => $acta->cuadra()
                ? 'Acta corregida: ahora cuadra y entra al consolidado.'
                : 'Acta corregida, pero sigue sin cuadrar: '.$acta->observacion,
        ]);
    }

    public function consolidado(Request $request): JsonResponse
    {
        return response()->json($this->consolidado->calcular(
            $request->filled('electoral_event_id') ? (int) $request->input('electoral_event_id') : null,
            $request->input('tipo'),
        ));
    }

    /**
     * Filtros por la ubicación del acta (Spec 0074).
     *
     * Dos formas de preguntar por lo mismo, porque son dos usos distintos:
     * `departamento_code`/`municipio_code` son exactos y sirven para agrupar
     * (un tablero pide «29» y quiere las 4.000 mesas de Tolima), mientras que
     * `departamento`/`municipio` son la casilla de búsqueda del panel, donde
     * quien escribe pone lo que recuerda —el nombre o el código— y espera que
     * «tolima» encuentre «TOLIMA».
     *
     * `lugar` solo existe como búsqueda: nadie teclea «UNIVERSIDAD COOPERATIVA
     * NUEVA SEDE» entero para encontrar su puesto.
     */
    private function filtrarPorUbicacion(Builder $query, Request $request): void
    {
        $como = DatabaseExpressions::caseInsensitiveLike();

        $query
            ->when(
                $request->filled('departamento_code'),
                fn ($q) => $q->where('departamento_code', $request->input('departamento_code'))
            )
            ->when(
                $request->filled('municipio_code'),
                fn ($q) => $q->where('municipio_code', $request->input('municipio_code'))
            )
            ->when(
                $request->filled('lugar'),
                fn ($q) => $q->where('lugar', $como, '%'.$request->input('lugar').'%')
            );

        foreach (['departamento', 'municipio'] as $eje) {
            $query->when($request->filled($eje), fn ($q) => $q->where(
                fn ($busqueda) => $busqueda
                    ->where($eje, $como, '%'.$request->input($eje).'%')
                    ->orWhere("{$eje}_code", $request->input($eje))
            ));
        }
    }

    /**
     * ¿Esta mesa ya estaba registrada? Solo cambia el código de respuesta —201
     * la primera vez, 200 al reenviar—, para que el cliente pueda distinguir un
     * alta de una relectura sin adivinar.
     */
    private function yaEstaba(StoreE14ActaRequest $request): bool
    {
        return E14Acta::query()
            ->where('tipo', $request->input('tipo'))
            ->where('zona', $request->input('zona'))
            ->where('puesto', $request->input('puesto'))
            ->where('mesa', $request->input('mesa'))
            ->exists();
    }
}
