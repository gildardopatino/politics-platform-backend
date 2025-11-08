<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Landing\BiografiaResource;
use App\Models\Tenant;
use App\Services\WasabiStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BiografiaAdminController extends Controller
{
    protected WasabiStorageService $wasabi;

    public function __construct(WasabiStorageService $wasabi)
    {
        $this->wasabi = $wasabi;
    }

    /**
     * Display the biografia (Admin)
     */
    public function show(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = Tenant::find($user->tenant_id);

        return response()->json([
            'data' => new BiografiaResource($tenant)
        ]);
    }

    /**
     * Update the biografia (Admin)
     */
    public function update(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = Tenant::find($user->tenant_id);

        $validator = Validator::make($request->all(), [
            'nombre' => 'sometimes|required|string|max:255',
            'cargo' => 'sometimes|required|string|max:255',
            'imagen' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
            'quienEs' => 'sometimes|required|array',
            'quienEs.titulo' => 'required|string',
            'quienEs.descripcion' => 'required|string',
            'quienEs.destacados' => 'sometimes|array',
            'quienEs.destacados.*' => 'string',
            'historia' => 'sometimes|required|array',
            'historia.titulo' => 'required|string',
            'historia.parrafos' => 'required|array',
            'historia.parrafos.*' => 'string',
            'valores' => 'sometimes|array',
            'valores.*.icono' => 'required|string',
            'valores.*.titulo' => 'required|string',
            'valores.*.descripcion' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        
        // Get existing biografia_data
        $biografiaData = $tenant->biografia_data ?? [];

        // Handle image upload if provided
        if ($request->hasFile('imagen')) {
            // Delete old image if exists and is a storage key
            if (isset($biografiaData['imagen']) && !filter_var($biografiaData['imagen'], FILTER_VALIDATE_URL)) {
                $this->wasabi->deleteFile($biografiaData['imagen'], $tenant);
            }
            
            $upload = $this->wasabi->uploadFile($request->file('imagen'), 'landing/biografia', $tenant);
            $data['imagen'] = $upload['key'];
        } elseif (isset($biografiaData['imagen'])) {
            // Keep existing image if not uploading new one
            $data['imagen'] = $biografiaData['imagen'];
        }

        // Merge with existing data
        $biografiaData = array_merge($biografiaData, $data);

        $tenant->biografia_data = $biografiaData;
        $tenant->save();

        return response()->json([
            'data' => new BiografiaResource($tenant),
            'message' => 'Biografía actualizada exitosamente'
        ]);
    }

    /**
     * Delete the biografia image (Admin)
     */
    public function deleteImage(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = Tenant::find($user->tenant_id);

        $biografiaData = $tenant->biografia_data ?? [];

        if (isset($biografiaData['imagen'])) {
            // Delete from storage if it's a storage key
            if (!filter_var($biografiaData['imagen'], FILTER_VALIDATE_URL)) {
                $this->wasabi->deleteFile($biografiaData['imagen'], $tenant);
            }
            
            unset($biografiaData['imagen']);
            $tenant->biografia_data = $biografiaData;
            $tenant->save();
        }

        return response()->json([
            'message' => 'Imagen de biografía eliminada exitosamente'
        ]);
    }
}
