<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Landing\LandingGaleriaResource;
use App\Models\LandingGaleria;
use App\Services\WasabiStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LandingGaleriaAdminController extends Controller
{
    protected WasabiStorageService $wasabi;

    public function __construct(WasabiStorageService $wasabi)
    {
        $this->wasabi = $wasabi;
    }

    /**
     * Display a listing of galeria items (Admin)
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $galeria = LandingGaleria::where('tenant_id', $user->tenant_id)
            ->orderBy('order')
            ->get();

        return response()->json([
            'data' => LandingGaleriaResource::collection($galeria)
        ]);
    }

    /**
     * Store a newly created galeria item (Admin)
     */
    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'titulo' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'imagen' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
            'categoria' => 'nullable|string|max:100',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Upload image
        if ($request->hasFile('imagen')) {
            $upload = $this->wasabi->uploadFile($request->file('imagen'), 'landing/galeria', $user->tenant);
            $data['imagen'] = $upload['key'];
        }

        $data['tenant_id'] = $user->tenant_id;

        $galeriaItem = LandingGaleria::create($data);

        return response()->json([
            'data' => new LandingGaleriaResource($galeriaItem),
            'message' => 'Foto agregada a la galería exitosamente'
        ], 201);
    }

    /**
     * Display the specified galeria item (Admin)
     */
    public function show(Request $request, LandingGaleria $galeriaItem): JsonResponse
    {
        return response()->json([
            'data' => new LandingGaleriaResource($galeriaItem)
        ]);
    }

    /**
     * Update the specified galeria item (Admin)
     */
    public function update(Request $request, LandingGaleria $galeriaItem): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'titulo' => 'sometimes|required|string|max:255',
            'descripcion' => 'nullable|string',
            'imagen' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'categoria' => 'nullable|string|max:100',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Upload new image if provided
        if ($request->hasFile('imagen')) {
            // Delete old image
            if ($galeriaItem->imagen) {
                $this->wasabi->deleteFile($galeriaItem->imagen, $galeriaItem->tenant);
            }
            
            $upload = $this->wasabi->uploadFile($request->file('imagen'), 'landing/galeria', $galeriaItem->tenant);
            $data['imagen'] = $upload['key'];
        }

        $galeriaItem->update($data);

        return response()->json([
            'data' => new LandingGaleriaResource($galeriaItem),
            'message' => 'Foto actualizada exitosamente'
        ]);
    }

    /**
     * Remove the specified galeria item (Admin)
     */
    public function destroy(LandingGaleria $galeriaItem): JsonResponse
    {
        // Delete image from storage
        if ($galeriaItem->imagen) {
            $this->wasabi->deleteFile($galeriaItem->imagen, $galeriaItem->tenant);
        }

        $galeriaItem->delete();

        return response()->json([
            'message' => 'Foto eliminada de la galería exitosamente'
        ]);
    }
}
