<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Voter;
use App\Services\PisamiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VoterController extends Controller
{
    /**
     * Display a listing of voters with filters
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search');
        $hasMultipleRecords = $request->input('has_multiple_records');

        $query = Voter::with(['barrio', 'corregimiento', 'vereda', 'meeting', 'createdBy']);

        if ($search) {
            $query->search($search);
        }

        if ($hasMultipleRecords !== null) {
            $query->where('has_multiple_records', $hasMultipleRecords);
        }

        $voters = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $voters->items(),
            'pagination' => [
                'total' => $voters->total(),
                'per_page' => $voters->perPage(),
                'current_page' => $voters->currentPage(),
                'last_page' => $voters->lastPage(),
                'from' => $voters->firstItem(),
                'to' => $voters->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created voter
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cedula' => 'required|string|max:20|unique:voters,cedula,NULL,id,tenant_id,' . auth()->user()->tenant_id,
            'nombres' => 'required|string|max:255',
            'apellidos' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'telefono' => 'nullable|string|max:20',
            'direccion' => 'nullable|string|max:500',
            'barrio_id' => 'nullable|exists:barrios,id',
            'corregimiento_id' => 'nullable|exists:corregimientos,id',
            'vereda_id' => 'nullable|exists:veredas,id',
            'meeting_id' => 'nullable|exists:meetings,id',
            'departamento_votacion' => 'nullable|string|max:255',
            'municipio_votacion' => 'nullable|string|max:255',
            'puesto_votacion' => 'nullable|string|max:255',
            'direccion_puesto' => 'nullable|string|max:500',
            'mesa_votacion' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $voter = Voter::create(array_merge(
            $validator->validated(),
            [
                'tenant_id' => auth()->user()->tenant_id,
                'created_by' => auth()->id(),
            ]
        ));

        $voter->load(['barrio', 'corregimiento', 'vereda', 'meeting']);

        return response()->json([
            'success' => true,
            'message' => 'Votante creado exitosamente',
            'data' => $voter,
        ], 201);
    }

    /**
     * Display the specified voter
     */
    public function show(Voter $voter): JsonResponse
    {
        $voter->load([
            'barrio.commune',
            'corregimiento',
            'vereda',
            'meeting.planner',
            'calls.survey',
            'calls.user',
            'createdBy',
        ]);

        return response()->json([
            'success' => true,
            'data' => $voter,
        ]);
    }

    /**
     * Update the specified voter
     */
    public function update(Request $request, Voter $voter): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cedula' => 'required|string|max:20|unique:voters,cedula,' . $voter->id . ',id,tenant_id,' . auth()->user()->tenant_id,
            'nombres' => 'required|string|max:255',
            'apellidos' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'telefono' => 'nullable|string|max:20',
            'direccion' => 'nullable|string|max:500',
            'barrio_id' => 'nullable|exists:barrios,id',
            'corregimiento_id' => 'nullable|exists:corregimientos,id',
            'vereda_id' => 'nullable|exists:veredas,id',
            'meeting_id' => 'nullable|exists:meetings,id',
            'departamento_votacion' => 'nullable|string|max:255',
            'municipio_votacion' => 'nullable|string|max:255',
            'puesto_votacion' => 'nullable|string|max:255',
            'direccion_puesto' => 'nullable|string|max:500',
            'mesa_votacion' => 'nullable|string|max:20',
            'has_multiple_records' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $voter->update($validator->validated());
        $voter->load(['barrio', 'corregimiento', 'vereda', 'meeting']);

        return response()->json([
            'success' => true,
            'message' => 'Votante actualizado exitosamente',
            'data' => $voter,
        ]);
    }

    /**
     * Remove the specified voter
     */
    public function destroy(Voter $voter): JsonResponse
    {
        $voter->delete();

        return response()->json([
            'success' => true,
            'message' => 'Votante eliminado exitosamente',
        ]);
    }

    /**
     * Get voters statistics
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total' => Voter::count(),
            'with_email' => Voter::whereNotNull('email')->count(),
            'with_phone' => Voter::whereNotNull('telefono')->count(),
            'with_voting_info' => Voter::whereNotNull('mesa_votacion')->count(),
            'with_multiple_records' => Voter::withMultipleRecords()->count(),
            'by_location_type' => [
                'barrio' => Voter::whereNotNull('barrio_id')->count(),
                'corregimiento' => Voter::whereNotNull('corregimiento_id')->count(),
                'vereda' => Voter::whereNotNull('vereda_id')->count(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Search voter by cedula
     */
    public function searchByCedula(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cedula' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $voter = Voter::where('cedula', $request->cedula)
            ->with(['barrio', 'corregimiento', 'vereda', 'meeting'])
            ->first();

        if (!$voter) {
            return response()->json([
                'success' => false,
                'message' => 'Votante no encontrado',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $voter,
        ]);
    }

    /**
     * Verify document from external PISAMI API
     * This endpoint is public (no authentication required)
     */
    public function verifyDocument(Request $request, PisamiService $pisamiService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cedula' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $cedula = $request->cedula;

        // Consumir API externa de PISAMI
        $data = $pisamiService->verifyDocument($cedula);

        if (!$data) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró información para la cédula proporcionada',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
