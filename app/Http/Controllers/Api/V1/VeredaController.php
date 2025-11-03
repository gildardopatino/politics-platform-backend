<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Vereda\StoreVeredaRequest;
use App\Http\Requests\Api\V1\Vereda\UpdateVeredaRequest;
use App\Models\Vereda;
use Illuminate\Http\JsonResponse;

class VeredaController extends Controller
{
    public function index(): JsonResponse
    {
        $veredas = Vereda::with(['municipality', 'corregimiento'])->get();
        return response()->json(['data' => $veredas]);
    }

    public function store(StoreVeredaRequest $request): JsonResponse
    {
        $vereda = Vereda::create($request->validated());
        return response()->json(['data' => $vereda->load(['municipality', 'corregimiento']), 'message' => 'Vereda created successfully'], 201);
    }

    public function show(Vereda $vereda): JsonResponse
    {
        return response()->json(['data' => $vereda->load(['municipality', 'corregimiento'])]);
    }

    public function update(UpdateVeredaRequest $request, Vereda $vereda): JsonResponse
    {
        $vereda->update($request->validated());
        return response()->json(['data' => $vereda->load(['municipality', 'corregimiento']), 'message' => 'Vereda updated successfully']);
    }

    public function destroy(Vereda $vereda): JsonResponse
    {
        $vereda->delete();
        return response()->json(['message' => 'Vereda deleted successfully']);
    }
}
