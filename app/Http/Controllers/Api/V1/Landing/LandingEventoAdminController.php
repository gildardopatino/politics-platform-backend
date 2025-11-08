<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Landing\LandingEventoResource;
use App\Models\LandingEvento;
use App\Services\WasabiStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LandingEventoAdminController extends Controller
{
    protected WasabiStorageService $wasabi;

    public function __construct(WasabiStorageService $wasabi)
    {
        $this->wasabi = $wasabi;
    }

    /**
     * Display a listing of eventos (Admin)
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $eventos = LandingEvento::where('tenant_id', $user->tenant_id)
            ->orderBy('fecha', 'desc')
            ->get();

        return response()->json([
            'data' => LandingEventoResource::collection($eventos)
        ]);
    }

    /**
     * Store a newly created evento (Admin)
     */
    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'titulo' => 'required|string|max:255',
            'fecha' => 'required|date',
            'hora' => 'required|string|max:50',
            'lugar' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'imagen' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'tipo' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Upload image if provided
        if ($request->hasFile('imagen')) {
            $upload = $this->wasabi->uploadFile($request->file('imagen'), 'landing/eventos', $user->tenant);
            $data['imagen'] = $upload['key'];
        }

        $data['tenant_id'] = $user->tenant_id;

        $evento = LandingEvento::create($data);

        return response()->json([
            'data' => new LandingEventoResource($evento),
            'message' => 'Evento creado exitosamente'
        ], 201);
    }

    /**
     * Display the specified evento (Admin)
     */
    public function show(Request $request, LandingEvento $evento): JsonResponse
    {
        return response()->json([
            'data' => new LandingEventoResource($evento)
        ]);
    }

    /**
     * Update the specified evento (Admin)
     */
    public function update(Request $request, LandingEvento $evento): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'titulo' => 'sometimes|required|string|max:255',
            'fecha' => 'sometimes|required|date',
            'hora' => 'sometimes|required|string|max:50',
            'lugar' => 'sometimes|required|string|max:255',
            'descripcion' => 'nullable|string',
            'imagen' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'tipo' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Upload new image if provided
        if ($request->hasFile('imagen')) {
            // Delete old image
            if ($evento->imagen) {
                $this->wasabi->deleteFile($evento->imagen, $evento->tenant);
            }
            
            $upload = $this->wasabi->uploadFile($request->file('imagen'), 'landing/eventos', $evento->tenant);
            $data['imagen'] = $upload['key'];
        }

        $evento->update($data);

        return response()->json([
            'data' => new LandingEventoResource($evento),
            'message' => 'Evento actualizado exitosamente'
        ]);
    }

    /**
     * Remove the specified evento (Admin)
     */
    public function destroy(LandingEvento $evento): JsonResponse
    {
        // Delete image from storage
        if ($evento->imagen) {
            $this->wasabi->deleteFile($evento->imagen, $evento->tenant);
        }

        $evento->delete();

        return response()->json([
            'message' => 'Evento eliminado exitosamente'
        ]);
    }
}
