<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TipoVotante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TipoVotanteController extends Controller
{
    /**
     * Listar todos los tipos de votante
     */
    public function index(Request $request): JsonResponse
    {
        $tipos = TipoVotante::orderBy('id')->get();

        return response()->json([
            'success' => true,
            'data' => $tipos,
        ]);
    }

    /**
     * Crear un nuevo tipo de votante
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'descripcion' => 'required|string|max:255|unique:tipo_votante,descripcion',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $tipo = TipoVotante::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Tipo de votante creado exitosamente',
            'data' => $tipo,
        ], 201);
    }

    /**
     * Mostrar un tipo de votante específico
     */
    public function show(TipoVotante $voterType): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $voterType,
        ]);
    }

    /**
     * Actualizar un tipo de votante
     */
    public function update(Request $request, TipoVotante $voterType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'descripcion' => 'required|string|max:255|unique:tipo_votante,descripcion,' . $voterType->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $voterType->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Tipo de votante actualizado exitosamente',
            'data' => $voterType,
        ]);
    }

    /**
     * Eliminar (soft delete) un tipo de votante
     */
    public function destroy(TipoVotante $voterType): JsonResponse
    {
        // Proteger el tipo por defecto (Elector)
        if ($voterType->id === 1) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar el tipo de votante por defecto.',
            ], 400);
        }

        $voterType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tipo de votante eliminado exitosamente',
        ]);
    }
}
