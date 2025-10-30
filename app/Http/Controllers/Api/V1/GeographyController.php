<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\GeographyResource;
use App\Models\Barrio;
use App\Models\Commune;
use App\Models\Corregimiento;
use App\Models\Department;
use App\Models\Municipality;
use Illuminate\Http\JsonResponse;

class GeographyController extends Controller
{
    /**
     * Get all departments
     */
    public function departments(): JsonResponse
    {
        $departments = Department::orderBy('nombre')->get();

        return response()->json([
            'data' => GeographyResource::collection($departments)
        ]);
    }

    /**
     * Get municipalities by department
     */
    public function municipalities(Department $department): JsonResponse
    {
        $municipalities = $department->municipalities()->orderBy('nombre')->get();

        return response()->json([
            'data' => GeographyResource::collection($municipalities)
        ]);
    }

    /**
     * Get communes by municipality
     */
    public function communes(Municipality $municipality): JsonResponse
    {
        $communes = $municipality->communes()->orderBy('nombre')->get();

        return response()->json([
            'data' => GeographyResource::collection($communes)
        ]);
    }

    /**
     * Get barrios by municipality (directos + de sus comunas)
     */
    public function barriosByMunicipality(Municipality $municipality): JsonResponse
    {
        // Obtener IDs de comunas del municipio
        $communeIds = $municipality->communes()->pluck('id');
        
        // Barrios directos del municipio O barrios de sus comunas
        $barrios = Barrio::where(function($query) use ($municipality, $communeIds) {
            $query->where('municipality_id', $municipality->id)
                  ->orWhereIn('commune_id', $communeIds);
        })->orderBy('nombre')->get();

        return response()->json([
            'data' => GeographyResource::collection($barrios)
        ]);
    }

    /**
     * Get barrios by commune
     */
    public function barriosByCommune(Commune $commune): JsonResponse
    {
        $barrios = $commune->barrios()->orderBy('nombre')->get();

        return response()->json([
            'data' => GeographyResource::collection($barrios)
        ]);
    }

    /**
     * Get corregimientos by municipality
     */
    public function corregimientos(Municipality $municipality): JsonResponse
    {
        $corregimientos = $municipality->corregimientos()->orderBy('nombre')->get();

        return response()->json([
            'data' => GeographyResource::collection($corregimientos)
        ]);
    }

    /**
     * Get veredas by corregimiento
     */
    public function veredasByCorregimiento(Corregimiento $corregimiento): JsonResponse
    {
        $veredas = $corregimiento->veredas()->orderBy('nombre')->get();

        return response()->json([
            'data' => GeographyResource::collection($veredas)
        ]);
    }

    /**
     * Get all veredas by municipality
     */
    public function veredasByMunicipality(Municipality $municipality): JsonResponse
    {
        $veredas = $municipality->veredas()->orderBy('nombre')->get();

        return response()->json([
            'data' => GeographyResource::collection($veredas)
        ]);
    }
}
