<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Corregimiento\StoreCorregimientoRequest;
use App\Http\Requests\Api\V1\Corregimiento\UpdateCorregimientoRequest;
use App\Models\Corregimiento;
use Illuminate\Http\JsonResponse;

class CorregimientoController extends Controller
{
    public function index(): JsonResponse
    {
        $corregimientos = Corregimiento::with('municipality')->get();
        return response()->json(['data' => $corregimientos]);
    }

    public function store(StoreCorregimientoRequest $request): JsonResponse
    {
        $corregimiento = Corregimiento::create($request->validated());
        return response()->json(['data' => $corregimiento->load('municipality'), 'message' => 'Corregimiento created successfully'], 201);
    }

    public function show(Corregimiento $corregimiento): JsonResponse
    {
        return response()->json(['data' => $corregimiento->load('municipality')]);
    }

    public function update(UpdateCorregimientoRequest $request, Corregimiento $corregimiento): JsonResponse
    {
        $corregimiento->update($request->validated());
        return response()->json(['data' => $corregimiento->load('municipality'), 'message' => 'Corregimiento updated successfully']);
    }

    public function destroy(Corregimiento $corregimiento): JsonResponse
    {
        $corregimiento->delete();
        return response()->json(['message' => 'Corregimiento deleted successfully']);
    }
}
