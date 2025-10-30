<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Priority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PriorityController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $priorities = Priority::ordered()->get();

        return response()->json([
            'data' => $priorities
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:priorities,name',
            'description' => 'nullable|string',
            'color' => 'required|string|size:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'order' => 'required|integer|min:0',
        ]);

        $priority = Priority::create($validated);

        return response()->json([
            'data' => $priority,
            'message' => 'Priority created successfully'
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Priority $priority): JsonResponse
    {
        return response()->json([
            'data' => $priority
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Priority $priority): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:priorities,name,' . $priority->id,
            'description' => 'nullable|string',
            'color' => 'sometimes|string|size:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'order' => 'sometimes|integer|min:0',
        ]);

        $priority->update($validated);

        return response()->json([
            'data' => $priority,
            'message' => 'Priority updated successfully'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Priority $priority): JsonResponse
    {
        // Verificar si tiene compromisos asociados
        if ($priority->commitments()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete priority with associated commitments'
            ], 422);
        }

        $priority->delete();

        return response()->json([
            'message' => 'Priority deleted successfully'
        ]);
    }
}
