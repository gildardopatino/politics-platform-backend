<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Barrio\StoreBarrioRequest;
use App\Http\Requests\Api\V1\Barrio\UpdateBarrioRequest;
use App\Models\Barrio;
use Illuminate\Http\JsonResponse;

class BarrioController extends Controller
{
    public function index(): JsonResponse
    {
        $barrios = Barrio::with(['municipality', 'commune'])->get();
        return response()->json(['data' => $barrios]);
    }

    public function store(StoreBarrioRequest $request): JsonResponse
    {
        $barrio = Barrio::create($request->validated());
        return response()->json(['data' => $barrio->load(['municipality', 'commune']), 'message' => 'Barrio created successfully'], 201);
    }

    public function show(Barrio $barrio): JsonResponse
    {
        return response()->json(['data' => $barrio->load(['municipality', 'commune'])]);
    }

    public function update(UpdateBarrioRequest $request, Barrio $barrio): JsonResponse
    {
        $barrio->update($request->validated());
        return response()->json(['data' => $barrio->load(['municipality', 'commune']), 'message' => 'Barrio updated successfully']);
    }

    public function destroy(Barrio $barrio): JsonResponse
    {
        $barrio->delete();
        return response()->json(['message' => 'Barrio deleted successfully']);
    }
}
