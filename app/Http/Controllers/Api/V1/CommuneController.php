<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Commune\StoreCommuneRequest;
use App\Http\Requests\Api\V1\Commune\UpdateCommuneRequest;
use App\Models\Commune;
use Illuminate\Http\JsonResponse;

class CommuneController extends Controller
{
    public function index(): JsonResponse
    {
        $communes = Commune::with('municipality')->get();
        return response()->json(['data' => $communes]);
    }

    public function store(StoreCommuneRequest $request): JsonResponse
    {
        $commune = Commune::create($request->validated());
        return response()->json(['data' => $commune->load('municipality'), 'message' => 'Commune created successfully'], 201);
    }

    public function show(Commune $commune): JsonResponse
    {
        return response()->json(['data' => $commune->load('municipality')]);
    }

    public function update(UpdateCommuneRequest $request, Commune $commune): JsonResponse
    {
        $commune->update($request->validated());
        return response()->json(['data' => $commune->load('municipality'), 'message' => 'Commune updated successfully']);
    }

    public function destroy(Commune $commune): JsonResponse
    {
        $commune->delete();
        return response()->json(['message' => 'Commune deleted successfully']);
    }
}
