<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Voter\UpsertVoterProfileRequest;
use App\Http\Resources\Api\V1\VoterProfileResource;
use App\Models\Voter;
use App\Models\VoterProfile;
use App\Services\VoterProfileService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perfil laboral del votante y bolsa de empleo (Spec 0094).
 *
 * Dato personal: todo cuelga del grupo autenticado + tenant y de sus propios
 * permisos. Nada de esto aparece en una ruta pública (Art. VII).
 */
class VoterProfileController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly VoterProfileService $perfiles) {}

    /**
     * La bolsa de empleo: perfiles del tenant, filtrados y paginados.
     *
     * Declarada **antes** de `/voters/{voter}` en `routes/api.php` (Spec 0006):
     * si no, el binding intenta resolver un votante con id «perfiles» y este
     * endpoint no existe.
     */
    public function index(Request $request): JsonResponse
    {
        $perfiles = $this->perfiles
            ->bolsa($request->only(['oficio', 'relacion', 'busca_empleo', 'q']))
            ->paginate($this->resolvePerPage());

        return $this->respondPaginated($perfiles, VoterProfileResource::class);
    }

    public function show(Voter $voter): JsonResponse
    {
        $perfil = VoterProfile::with('occupations')->where('voter_id', $voter->id)->first();

        // Un votante sin perfil no es un error: es alguien a quien todavía no se
        // le ha preguntado. El formulario abre vacío.
        if ($perfil === null) {
            return $this->respondData(null);
        }

        return $this->respondData(new VoterProfileResource($perfil));
    }

    public function update(UpsertVoterProfileRequest $request, Voter $voter): JsonResponse
    {
        $perfil = $this->perfiles->guardar($voter, $request->validated(), auth()->id());

        return $this->respondData(
            new VoterProfileResource($perfil),
            'Perfil laboral guardado exitosamente'
        );
    }
}
