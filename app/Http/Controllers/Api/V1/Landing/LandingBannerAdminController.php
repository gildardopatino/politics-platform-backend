<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Landing\LandingBannerResource;
use App\Models\LandingBanner;
use App\Services\WasabiStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class LandingBannerAdminController extends Controller
{
    protected WasabiStorageService $wasabi;

    public function __construct(WasabiStorageService $wasabi)
    {
        $this->wasabi = $wasabi;
    }

    /**
     * Display a listing of banners (Admin)
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $banners = LandingBanner::where('tenant_id', $user->tenant_id)
            ->orderBy('order')
            ->get();

        return response()->json([
            'data' => LandingBannerResource::collection($banners)
        ]);
    }

    /**
     * Store a newly created banner (Admin)
     */
    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
            'cta_text' => 'nullable|string|max:100',
            'cta_link' => 'nullable|string|max:500',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Upload image
        if ($request->hasFile('image')) {
            $upload = $this->wasabi->uploadFile($request->file('image'), 'landing/banners', $user->tenant);
            $data['image'] = $upload['key'];
        }

        $data['tenant_id'] = $user->tenant_id;

        $banner = LandingBanner::create($data);

        return response()->json([
            'data' => new LandingBannerResource($banner),
            'message' => 'Banner creado exitosamente'
        ], 201);
    }

    /**
     * Display the specified banner (Admin)
     */
    public function show(Request $request, LandingBanner $banner): JsonResponse
    {
        return response()->json([
            'data' => new LandingBannerResource($banner)
        ]);
    }

    /**
     * Update the specified banner (Admin)
     */
    public function update(Request $request, LandingBanner $banner): JsonResponse
    {
        Log::info('Update banner request', [
            'banner_id' => $banner->id,
            'has_file' => $request->hasFile('image'),
            'all_input' => $request->except(['image']),
            'files' => $request->allFiles(),
        ]);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'cta_text' => 'nullable|string|max:100',
            'cta_link' => 'nullable|string|max:500',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            Log::error('Validation failed', ['errors' => $validator->errors()]);
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $oldImage = $banner->image;

        // Upload new image if provided
        if ($request->hasFile('image')) {
            Log::info('Processing image upload', [
                'old_image' => $oldImage,
                'file_size' => $request->file('image')->getSize(),
                'file_name' => $request->file('image')->getClientOriginalName(),
            ]);

            try {
                // Upload new image first
                $upload = $this->wasabi->uploadFile($request->file('image'), 'landing/banners', $banner->tenant);
                $data['image'] = $upload['key'];
                
                Log::info('Image uploaded successfully', [
                    'new_key' => $upload['key'],
                ]);

                // Delete old image after successful upload
                if ($oldImage) {
                    $this->wasabi->deleteFile($oldImage, $banner->tenant);
                    Log::info('Old image deleted', ['old_key' => $oldImage]);
                }
            } catch (\Exception $e) {
                Log::error('Error uploading image', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json(['error' => 'Error al subir la imagen: ' . $e->getMessage()], 500);
            }
        }

        $banner->update($data);
        $banner->refresh(); // Refresh to get updated data

        Log::info('Banner updated', [
            'banner_id' => $banner->id,
            'new_image' => $banner->image,
        ]);

        return response()->json([
            'data' => new LandingBannerResource($banner),
            'message' => 'Banner actualizado exitosamente'
        ]);
    }

    /**
     * Remove the specified banner (Admin)
     */
    public function destroy(LandingBanner $banner): JsonResponse
    {
        // Delete image from storage
        if ($banner->image) {
            $this->wasabi->deleteFile($banner->image, $banner->tenant);
        }

        $banner->delete();

        return response()->json([
            'message' => 'Banner eliminado exitosamente'
        ]);
    }
}
