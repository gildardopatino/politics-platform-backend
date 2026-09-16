<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Voter\StoreVoterResumeRequest;
use App\Http\Resources\Api\V1\VoterResumeResource;
use App\Models\Voter;
use App\Models\VoterResume;
use App\Scopes\TenantScope;
use App\Services\VoterResumeService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hojas de vida del elector (Spec 0096).
 *
 * PII densa: todo detrás de los permisos de la 0094, nada público, y el archivo
 * solo por ruta firmada. El binding de `{voter}` y `{resume}` ya está acotado
 * por `TenantScope`, así que una hoja de otra campaña responde 404 —igual que
 * una inexistente, para no confirmar que existe (Art. III y VII).
 */
class VoterResumeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly VoterResumeService $hojas) {}

    public function index(Voter $voter): JsonResponse
    {
        $hojas = VoterResume::with('subidoPor')
            ->where('voter_id', $voter->id)
            ->latest('id')
            ->get();

        return $this->respondData(VoterResumeResource::collection($hojas));
    }

    public function store(StoreVoterResumeRequest $request, Voter $voter): JsonResponse
    {
        $hoja = $this->hojas->guardar($voter, $request->file('archivo'), auth()->id());

        return $this->respondData(
            new VoterResumeResource($hoja->load('subidoPor')),
            'Hoja de vida adjuntada exitosamente',
            201
        );
    }

    public function destroy(VoterResume $resume): JsonResponse
    {
        $this->hojas->borrar($resume);

        return $this->respondMessage('Hoja de vida eliminada exitosamente');
    }

    /**
     * Descarga por ruta firmada, fuera del grupo con sesión.
     *
     * Aquí no hay usuario, así que `TenantScope` no filtra: quien manda es la
     * firma, y por eso se comprueba que el tenant firmado sea el dueño de la
     * hoja. Mismo patrón que el archivo del acta E-14 (Spec 0071).
     */
    public function archivo(Request $request, int $resume): StreamedResponse|JsonResponse
    {
        $hoja = VoterResume::withoutGlobalScope(TenantScope::class)->find($resume);

        if (! $hoja || (int) $request->query('tenant') !== (int) $hoja->tenant_id) {
            return $this->respondError('Hoja de vida no encontrada.', 404);
        }

        if (! $this->hojas->existe($hoja)) {
            return $this->respondError('La hoja de vida no tiene archivo almacenado.', 404);
        }

        return $this->hojas->descargar($hoja);
    }
}
