<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Landing\LandingTestimonioResource;
use App\Models\LandingTestimonio;
use App\Services\WasabiStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LandingTestimonioAdminController extends Controller
{
    protected WasabiStorageService $wasabi;

    public function __construct(WasabiStorageService $wasabi)
    {
        $this->wasabi = $wasabi;
    }

    /**
     * Display a listing of testimonios (Admin)
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $testimonios = LandingTestimonio::where('tenant_id', $user->tenant_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => LandingTestimonioResource::collection($testimonios)
        ]);
    }

    /**
     * Store a newly created testimonio (Admin)
     */
    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
            'ocupacion' => 'nullable|string|max:255',
            'municipio' => 'nullable|string|max:255',
            'testimonio' => 'required|string',
            'foto' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'calificacion' => 'nullable|integer|min:1|max:5',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Upload foto if provided
        if ($request->hasFile('foto')) {
            $upload = $this->wasabi->uploadFile($request->file('foto'), 'landing/testimonios', $user->tenant);
            $data['foto'] = $upload['key'];
        }

        $data['tenant_id'] = $user->tenant_id;

        $testimonio = LandingTestimonio::create($data);

        return response()->json([
            'data' => new LandingTestimonioResource($testimonio),
            'message' => 'Testimonio creado exitosamente'
        ], 201);
    }

    /**
     * Display the specified testimonio (Admin)
     */
    public function show(Request $request, LandingTestimonio $testimonio): JsonResponse
    {
        return response()->json([
            'data' => new LandingTestimonioResource($testimonio)
        ]);
    }

    /**
     * Update the specified testimonio (Admin)
     */
    public function update(Request $request, LandingTestimonio $testimonio): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'sometimes|required|string|max:255',
            'ocupacion' => 'nullable|string|max:255',
            'municipio' => 'nullable|string|max:255',
            'testimonio' => 'sometimes|required|string',
            'foto' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'calificacion' => 'nullable|integer|min:1|max:5',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Upload new foto if provided
        if ($request->hasFile('foto')) {
            // Delete old foto
            if ($testimonio->foto) {
                $this->wasabi->deleteFile($testimonio->foto, $testimonio->tenant);
            }
            
            $upload = $this->wasabi->uploadFile($request->file('foto'), 'landing/testimonios', $testimonio->tenant);
            $data['foto'] = $upload['key'];
        }

        $testimonio->update($data);

        return response()->json([
            'data' => new LandingTestimonioResource($testimonio),
            'message' => 'Testimonio actualizado exitosamente'
        ]);
    }

    /**
     * Remove the specified testimonio (Admin)
     */
    public function destroy(LandingTestimonio $testimonio): JsonResponse
    {
        // Delete foto from storage
        if ($testimonio->foto) {
            $this->wasabi->deleteFile($testimonio->foto, $testimonio->tenant);
        }

        $testimonio->delete();

        return response()->json([
            'message' => 'Testimonio eliminado exitosamente'
        ]);
    }
}
