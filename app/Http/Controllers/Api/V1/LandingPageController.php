<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Landing\BiografiaResource;
use App\Http\Resources\Api\V1\Landing\LandingBannerResource;
use App\Http\Resources\Api\V1\Landing\LandingEventoResource;
use App\Http\Resources\Api\V1\Landing\LandingGaleriaResource;
use App\Http\Resources\Api\V1\Landing\LandingPropuestaResource;
use App\Http\Resources\Api\V1\Landing\LandingSocialFeedResource;
use App\Http\Resources\Api\V1\Landing\LandingTestimonioResource;
use App\Models\LandingBanner;
use App\Models\LandingContacto;
use App\Models\LandingEvento;
use App\Models\LandingGaleria;
use App\Models\LandingPropuesta;
use App\Models\LandingSocialFeed;
use App\Models\LandingTestimonio;
use App\Models\LandingVoluntario;
use App\Models\Tenant;
use App\Services\WasabiStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LandingPageController extends Controller
{
    protected WasabiStorageService $wasabi;

    public function __construct(WasabiStorageService $wasabi)
    {
        $this->wasabi = $wasabi;
    }

    // ==================== PUBLIC ENDPOINTS ====================

    /**
     * GET /landingpage/banners - Public
     */
    public function getBanners(Request $request): JsonResponse
    {
        $tenantSlug = $request->header('X-Tenant-Slug') ?? $request->query('tenant');
        
        if (!$tenantSlug) {
            return response()->json(['error' => 'Tenant slug is required'], 400);
        }

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $banners = LandingBanner::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        return response()->json(LandingBannerResource::collection($banners));
    }

    /**
     * GET /landingpage/biografia - Public
     */
    public function getBiografia(Request $request): JsonResponse
    {
        $tenantSlug = $request->header('X-Tenant-Slug') ?? $request->query('tenant');
        
        if (!$tenantSlug) {
            return response()->json(['error' => 'Tenant slug is required'], 400);
        }

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        return response()->json(new BiografiaResource($tenant));
    }

    /**
     * GET /landingpage/propuestas - Public
     */
    public function getPropuestas(Request $request): JsonResponse
    {
        $tenantSlug = $request->header('X-Tenant-Slug') ?? $request->query('tenant');
        
        if (!$tenantSlug) {
            return response()->json(['error' => 'Tenant slug is required'], 400);
        }

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $propuestas = LandingPropuesta::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        return response()->json(LandingPropuestaResource::collection($propuestas));
    }

    /**
     * GET /landingpage/eventos - Public
     */
    public function getEventos(Request $request): JsonResponse
    {
        $tenantSlug = $request->header('X-Tenant-Slug') ?? $request->query('tenant');
        
        if (!$tenantSlug) {
            return response()->json(['error' => 'Tenant slug is required'], 400);
        }

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $eventos = LandingEvento::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('fecha', 'desc')
            ->get();

        return response()->json(LandingEventoResource::collection($eventos));
    }

    /**
     * GET /landingpage/galeria - Public
     */
    public function getGaleria(Request $request): JsonResponse
    {
        $tenantSlug = $request->header('X-Tenant-Slug') ?? $request->query('tenant');
        
        if (!$tenantSlug) {
            return response()->json(['error' => 'Tenant slug is required'], 400);
        }

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $galeria = LandingGaleria::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        return response()->json(LandingGaleriaResource::collection($galeria));
    }

    /**
     * GET /landingpage/testimonios - Public
     */
    public function getTestimonios(Request $request): JsonResponse
    {
        $tenantSlug = $request->header('X-Tenant-Slug') ?? $request->query('tenant');
        
        if (!$tenantSlug) {
            return response()->json(['error' => 'Tenant slug is required'], 400);
        }

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $testimonios = LandingTestimonio::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get();

        return response()->json(LandingTestimonioResource::collection($testimonios));
    }

    /**
     * GET /landingpage/social-feed - Public
     */
    public function getSocialFeed(Request $request): JsonResponse
    {
        $tenantSlug = $request->header('X-Tenant-Slug') ?? $request->query('tenant');
        
        if (!$tenantSlug) {
            return response()->json(['error' => 'Tenant slug is required'], 400);
        }

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $socialFeed = LandingSocialFeed::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('fecha', 'desc')
            ->get();

        return response()->json(LandingSocialFeedResource::collection($socialFeed));
    }

    /**
     * POST /landingpage/voluntarios - Public
     */
    public function storeVoluntario(Request $request): JsonResponse
    {
        $tenantSlug = $request->header('X-Tenant-Slug') ?? $request->input('tenant');
        
        if (!$tenantSlug) {
            return response()->json(['error' => 'Tenant slug is required'], 400);
        }

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'telefono' => 'required|string|max:50',
            'ciudad' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $voluntario = LandingVoluntario::create([
            'tenant_id' => $tenant->id,
            ...$validator->validated()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Voluntario registrado exitosamente',
            'id' => $voluntario->id
        ], 201);
    }

    /**
     * POST /landingpage/contacto - Public
     */
    public function storeContacto(Request $request): JsonResponse
    {
        $tenantSlug = $request->header('X-Tenant-Slug') ?? $request->input('tenant');
        
        if (!$tenantSlug) {
            return response()->json(['error' => 'Tenant slug is required'], 400);
        }

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'telefono' => 'nullable|string|max:50',
            'mensaje' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        LandingContacto::create([
            'tenant_id' => $tenant->id,
            ...$validator->validated()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Mensaje enviado exitosamente'
        ], 201);
    }

    // ==================== PROTECTED ENDPOINTS (Admin) ====================
    // Continuaré en el siguiente mensaje debido al límite de caracteres...
}
