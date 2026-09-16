<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OccupationResource;
use App\Models\Occupation;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de oficios (Spec 0094).
 *
 * Solo lectura: el catálogo es global y se siembra. Dejarlo escribir desde el
 * formulario del votante lo llenaría de grafías sueltas y rompería justo lo que
 * los sinónimos vienen a arreglar.
 */
class OccupationController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $oficios = Occupation::activos()->orderBy('nombre')->get();

        return $this->respondData(OccupationResource::collection($oficios));
    }
}
