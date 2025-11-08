<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Landing\LandingSocialFeedResource;
use App\Models\LandingSocialFeed;
use App\Services\WasabiStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LandingSocialFeedAdminController extends Controller
{
    protected WasabiStorageService $wasabi;

    public function __construct(WasabiStorageService $wasabi)
    {
        $this->wasabi = $wasabi;
    }

    /**
     * Display a listing of social feed posts (Admin)
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $socialFeed = LandingSocialFeed::where('tenant_id', $user->tenant_id)
            ->orderBy('fecha', 'desc')
            ->get();

        return response()->json([
            'data' => LandingSocialFeedResource::collection($socialFeed)
        ]);
    }

    /**
     * Store a newly created social feed post (Admin)
     */
    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'plataforma' => 'required|in:twitter,facebook,instagram',
            'usuario' => 'required|string|max:255',
            'contenido' => 'required|string',
            'fecha' => 'required|date',
            'likes' => 'nullable|integer|min:0',
            'compartidos' => 'nullable|integer|min:0',
            'comentarios' => 'nullable|integer|min:0',
            'imagen' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Upload image if provided
        if ($request->hasFile('imagen')) {
            $upload = $this->wasabi->uploadFile($request->file('imagen'), 'landing/social', $user->tenant);
            $data['imagen'] = $upload['key'];
        }

        $data['tenant_id'] = $user->tenant_id;

        $socialPost = LandingSocialFeed::create($data);

        return response()->json([
            'data' => new LandingSocialFeedResource($socialPost),
            'message' => 'Post creado exitosamente'
        ], 201);
    }

    /**
     * Display the specified social feed post (Admin)
     */
    public function show(Request $request, LandingSocialFeed $socialFeedPost): JsonResponse
    {
        return response()->json([
            'data' => new LandingSocialFeedResource($socialFeedPost)
        ]);
    }

    /**
     * Update the specified social feed post (Admin)
     */
    public function update(Request $request, LandingSocialFeed $socialFeedPost): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plataforma' => 'sometimes|required|in:twitter,facebook,instagram',
            'usuario' => 'sometimes|required|string|max:255',
            'contenido' => 'sometimes|required|string',
            'fecha' => 'sometimes|required|date',
            'likes' => 'nullable|integer|min:0',
            'compartidos' => 'nullable|integer|min:0',
            'comentarios' => 'nullable|integer|min:0',
            'imagen' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Upload new image if provided
        if ($request->hasFile('imagen')) {
            // Delete old image
            if ($socialFeedPost->imagen) {
                $this->wasabi->deleteFile($socialFeedPost->imagen, $socialFeedPost->tenant);
            }
            
            $upload = $this->wasabi->uploadFile($request->file('imagen'), 'landing/social', $socialFeedPost->tenant);
            $data['imagen'] = $upload['key'];
        }

        $socialFeedPost->update($data);

        return response()->json([
            'data' => new LandingSocialFeedResource($socialFeedPost),
            'message' => 'Post actualizado exitosamente'
        ]);
    }

    /**
     * Remove the specified social feed post (Admin)
     */
    public function destroy(LandingSocialFeed $socialFeedPost): JsonResponse
    {
        // Delete image from storage
        if ($socialFeedPost->imagen) {
            $this->wasabi->deleteFile($socialFeedPost->imagen, $socialFeedPost->tenant);
        }

        $socialFeedPost->delete();

        return response()->json([
            'message' => 'Post eliminado exitosamente'
        ]);
    }
}
