<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Landing\LandingPropuestaResource;
use App\Models\LandingPropuesta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LandingPropuestaAdminController extends Controller
{
    /**
     * Display a listing of propuestas (Admin)
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $propuestas = LandingPropuesta::where('tenant_id', $user->tenant_id)
            ->orderBy('order')
            ->get();

        return response()->json([
            'data' => LandingPropuestaResource::collection($propuestas)
        ]);
    }

    /**
     * Store a newly created propuesta (Admin)
     */
    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'categoria' => 'required|string|max:255',
            'titulo' => 'required|string|max:255',
            'descripcion' => 'required|string',
            'puntos_clave' => 'required|array',
            'puntos_clave.*' => 'string',
            'icono' => 'required|string|in:shield,leaf,book-open,heart,briefcase,construction',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['tenant_id'] = $user->tenant_id;

        $propuesta = LandingPropuesta::create($data);

        return response()->json([
            'data' => new LandingPropuestaResource($propuesta),
            'message' => 'Propuesta creada exitosamente'
        ], 201);
    }

    /**
     * Display the specified propuesta (Admin)
     */
    public function show(Request $request, LandingPropuesta $propuesta): JsonResponse
    {
        return response()->json([
            'data' => new LandingPropuestaResource($propuesta)
        ]);
    }

    /**
     * Update the specified propuesta (Admin)
     */
    public function update(Request $request, LandingPropuesta $propuesta): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'categoria' => 'sometimes|required|string|max:255',
            'titulo' => 'sometimes|required|string|max:255',
            'descripcion' => 'sometimes|required|string',
            'puntos_clave' => 'sometimes|required|array',
            'puntos_clave.*' => 'string',
            'icono' => 'sometimes|required|string|in:shield,leaf,book-open,heart,briefcase,construction',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $propuesta->update($validator->validated());

        return response()->json([
            'data' => new LandingPropuestaResource($propuesta),
            'message' => 'Propuesta actualizada exitosamente'
        ]);
    }

    /**
     * Remove the specified propuesta (Admin)
     */
    public function destroy(LandingPropuesta $propuesta): JsonResponse
    {
        $propuesta->delete();

        return response()->json([
            'message' => 'Propuesta eliminada exitosamente'
        ]);
    }
}
