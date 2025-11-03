<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Municipality\StoreMunicipalityRequest;
use App\Http\Requests\Api\V1\Municipality\UpdateMunicipalityRequest;
use App\Models\Municipality;
use Illuminate\Http\JsonResponse;

class MunicipalityController extends Controller
{
    public function index(): JsonResponse
    {
        $municipalities = Municipality::with('department')->get();
        return response()->json(['data' => $municipalities]);
    }

    public function store(StoreMunicipalityRequest $request): JsonResponse
    {
        $municipality = Municipality::create($request->validated());
        return response()->json(['data' => $municipality->load('department'), 'message' => 'Municipality created successfully'], 201);
    }

    public function show(Municipality $municipality): JsonResponse
    {
        return response()->json(['data' => $municipality->load('department')]);
    }

    public function update(UpdateMunicipalityRequest $request, Municipality $municipality): JsonResponse
    {
        $municipality->update($request->validated());
        return response()->json(['data' => $municipality->load('department'), 'message' => 'Municipality updated successfully']);
    }

    public function destroy(Municipality $municipality): JsonResponse
    {
        $municipality->delete();
        return response()->json(['message' => 'Municipality deleted successfully']);
    }
}
