<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\E14ActaResource;
use App\Models\E14Acta;
use App\Scopes\TenantScope;
use App\Services\E14\E14ArchivoService;
use App\Services\E14\E14ColaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La cara de la API que mira al worker (Spec 0071).
 *
 * El worker no navega ni tiene sesión: pide trabajo, lo hace y devuelve el
 * resultado. Son tres llamadas —reclamar, descargar el PDF, publicar— y todas
 * tienen que sobrevivir a que el proceso se caiga en medio.
 */
class E14WorkerController extends Controller
{
    public function __construct(
        private readonly E14ColaService $cola,
        private readonly E14ArchivoService $archivos,
    ) {}

    /**
     * Entrega la siguiente acta pendiente, reclamándola para quien pregunta.
     *
     * Responde `204` cuando no hay nada que hacer: es la señal con la que el
     * worker se echa a dormir un rato en vez de girar en vacío.
     */
    public function siguiente(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'tipos' => 'nullable|array',
            'tipos.*' => Rule::in(E14Acta::TIPOS),
        ]);

        $acta = $this->cola->reclamar($datos['tipos'] ?? []);

        if (! $acta) {
            return response()->json(['data' => null, 'message' => 'No hay actas pendientes.'], 204);
        }

        return response()->json([
            'data' => new E14ActaResource($acta->load('electoralEvent')),
            // Se emite aquí y no se guarda: una URL firmada guardada es una URL
            // que caduca en la base de datos.
            'archivo_url' => $this->archivos->urlFirmada($acta),
        ]);
    }

    /**
     * Descarga del PDF por URL firmada.
     *
     * Ruta pública **por firma**, no por descuido: el worker la recibe ya
     * firmada al reclamar y la usa en segundos. La firma cubre el id del acta y
     * el del tenant, así que no se puede editar para pedir otra ni transferir a
     * otra campaña; y dura minutos, no los seis días del máximo de S3.
     */
    public function archivo(Request $request, int $acta): StreamedResponse|JsonResponse
    {
        // Sin scope de tenant: aquí no hay sesión. Quien manda es la firma, y
        // por eso se comprueba que el tenant firmado sea el dueño del acta.
        $modelo = E14Acta::withoutGlobalScope(TenantScope::class)->find($acta);

        if (! $modelo || (int) $request->query('tenant') !== (int) $modelo->tenant_id) {
            return response()->json(['message' => 'Acta no encontrada.'], 404);
        }

        if (! $this->archivos->existe($modelo)) {
            return response()->json(['message' => 'El acta no tiene archivo almacenado.'], 404);
        }

        return $this->archivos->descargar($modelo);
    }
}
