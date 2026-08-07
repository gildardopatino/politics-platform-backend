<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Voter\StoreVoterRequest;
use App\Http\Requests\Api\V1\Voter\UpdateVoterRequest;
use App\Http\Resources\Api\V1\VoterResource;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Services\DocumentVerificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VoterController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of voters with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Voter::with(['barrio', 'corregimiento', 'vereda', 'meeting', 'createdBy', 'tipoVotante']);

        if ($search = $request->input('search')) {
            $query->search($search);
        }

        if ($request->input('has_multiple_records') !== null) {
            $query->where('has_multiple_records', $request->boolean('has_multiple_records'));
        }

        $voters = $query->latest()->paginate($this->resolvePerPage());

        return $this->respondPaginated($voters, VoterResource::class);
    }

    /**
     * Store a newly created voter.
     */
    public function store(StoreVoterRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (empty($data['tipo_votante_id'])) {
            $data['tipo_votante_id'] = 1; // Elector por defecto
        }

        // tenant_id is auto-filled by the HasTenant trait; created_by is set here.
        $voter = Voter::create(array_merge($data, [
            'created_by' => auth()->id(),
        ]));

        $voter->load(['barrio', 'corregimiento', 'vereda', 'meeting', 'tipoVotante']);

        return $this->respondData(new VoterResource($voter), 'Votante creado exitosamente', 201);
    }

    /**
     * Display the specified voter.
     */
    public function show(Voter $voter): JsonResponse
    {
        if (! auth()->user()?->can('view', $voter)) {
            return $this->respondError('No tienes permiso para ver este votante.', 403);
        }

        $voter->load([
            'barrio.commune',
            'corregimiento',
            'vereda',
            'meeting.planner',
            'calls.survey',
            'calls.user',
            'createdBy',
            'tipoVotante',
        ]);

        return $this->respondData(new VoterResource($voter));
    }

    /**
     * Update the specified voter.
     */
    public function update(UpdateVoterRequest $request, Voter $voter): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('tipo_votante_id', $data) && empty($data['tipo_votante_id'])) {
            $data['tipo_votante_id'] = 1; // Elector por defecto si viene vacío
        }

        $voter->update($data);
        $voter->load(['barrio', 'corregimiento', 'vereda', 'meeting', 'tipoVotante']);

        return $this->respondData(new VoterResource($voter), 'Votante actualizado exitosamente');
    }

    /**
     * Remove the specified voter.
     */
    public function destroy(Voter $voter): JsonResponse
    {
        if (! auth()->user()?->can('delete', $voter)) {
            return $this->respondError('No tienes permiso para eliminar este votante.', 403);
        }

        $voter->delete();

        return $this->respondMessage('Votante eliminado exitosamente');
    }

    /**
     * Get voters statistics.
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

        return $this->respondData($stats);
    }

    /**
     * Search voter by cedula.
     */
    public function searchByCedula(Request $request): JsonResponse
    {
        $request->validate(['cedula' => 'required|string']);

        $voter = Voter::where('cedula', $request->cedula)
            ->with(['barrio', 'corregimiento', 'vereda', 'meeting', 'tipoVotante'])
            ->first();

        if (! $voter) {
            return $this->respondError('Votante no encontrado', 404);
        }

        return $this->respondData(new VoterResource($voter));
    }

    /**
     * Verify document from external PISAMI API, falling back to local leads.
     *
     * Authenticated + `view_voters`, so the lookup runs inside the caller's
     * tenant and may return the full record (the call-center form captures
     * address and voting place). The public counterpart lives at
     * `GET /meetings/public/{qr_code}/verify-document` and returns only name
     * and contact — see Spec 0026.
     *
     * NOTE: response shape kept as { success, data, source } — not migrated to
     * the standard envelope because the frontend reads it as-is.
     */
    public function verifyDocument(Request $request, DocumentVerificationService $verificador): JsonResponse
    {
        $request->validate(['cedula' => 'required|string|max:20']);

        $resultado = $verificador->verify($request->query('cedula'));

        if (! $resultado) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró información para la cédula proporcionada en PISAMI ni en la base de datos local',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $resultado['data'],
            'source' => $resultado['source'],
        ]);
    }

    /**
     * Get voters grouped by voting place (puesto_votacion).
     * Tolima voters are grouped; voters outside Tolima are returned as "external".
     */
    public function byVotingPlace(): JsonResponse
    {
        // Single pass over Tolima voters, grouped in memory (avoids N+1 per place).
        $tolimaColumns = ['id', 'cedula', 'nombres', 'apellidos', 'email', 'telefono', 'direccion', 'mesa_votacion', 'puesto_votacion', 'direccion_votacion', 'departamento_votacion', 'municipio_votacion'];

        $votingPlaces = Voter::select($tolimaColumns)
            ->whereNotNull('puesto_votacion')
            ->where('puesto_votacion', '!=', '')
            ->where('departamento_votacion', 'TOLIMA')
            ->orderBy('puesto_votacion')
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->get()
            ->groupBy('puesto_votacion')
            ->map(function ($voters, $puesto) {
                $first = $voters->first();

                return [
                    'puesto_votacion' => $puesto,
                    'direccion_votacion' => $first->direccion_votacion,
                    'departamento_votacion' => $first->departamento_votacion,
                    'municipio_votacion' => $first->municipio_votacion,
                    'total_votantes' => $voters->count(),
                    'detalle_votacion' => $voters->map(fn ($v) => [
                        'id' => $v->id,
                        'cedula' => $v->cedula,
                        'nombre_completo' => trim($v->nombres.' '.$v->apellidos),
                        'email' => $v->email,
                        'telefono' => $v->telefono,
                        'direccion' => $v->direccion,
                        'mesa_votacion' => $v->mesa_votacion,
                    ])->values(),
                ];
            })
            ->values();

        // Voters outside Tolima.
        $externalVoters = Voter::select(['id', 'cedula', 'nombres', 'apellidos', 'email', 'telefono', 'direccion', 'departamento_votacion', 'municipio_votacion', 'puesto_votacion', 'direccion_votacion', 'mesa_votacion'])
            ->whereNotNull('departamento_votacion')
            ->where('departamento_votacion', '!=', 'TOLIMA')
            ->orderBy('departamento_votacion')
            ->orderBy('municipio_votacion')
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'cedula' => $v->cedula,
                'nombre_completo' => trim($v->nombres.' '.$v->apellidos),
                'email' => $v->email,
                'telefono' => $v->telefono,
                'direccion' => $v->direccion,
                'departamento_votacion' => $v->departamento_votacion,
                'municipio_votacion' => $v->municipio_votacion,
                'puesto_votacion' => $v->puesto_votacion,
                'direccion_votacion' => $v->direccion_votacion,
                'mesa_votacion' => $v->mesa_votacion,
            ]);

        return $this->respondData([
            'puestos' => $votingPlaces,
            'total_puestos' => $votingPlaces->count(),
            'total_votantes_tolima' => $votingPlaces->sum('total_votantes'),
            'votantes_externos' => $externalVoters,
            'total_votantes_externos' => $externalVoters->count(),
        ]);
    }

    /**
     * Public webhook (n8n): voters pending registraduría lookup.
     * Returns a raw array — response shape kept stable for the webhook consumer.
     */
    public function pendientesRegistraduria(Request $request): JsonResponse
    {
        $voters = Voter::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->select('id', 'cedula')
            ->whereNull('departamento_votacion')
            ->limit(100)
            ->get();

        return response()->json($voters);
    }

    /**
     * Public webhook (n8n): update voter registraduría data.
     * Response shape kept as { success, message, data } for the webhook consumer.
     */
    public function actualizarRegistraduria(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|exists:voters,id',
            'departamento_votacion' => 'required|string|max:255',
            'municipio_votacion' => 'required|string|max:255',
            'puesto_votacion' => 'required|string|max:255',
            'direccion_votacion' => 'nullable|string|max:500',
            'mesa_votacion' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $votingPlace = VotingPlace::firstOrCreate(
            [
                'departamento_votacion' => $data['departamento_votacion'],
                'municipio_votacion' => $data['municipio_votacion'],
                'puesto_votacion' => $data['puesto_votacion'],
            ],
            [
                'direccion_votacion' => $data['direccion_votacion'] ?? null,
            ]
        );

        // Public webhook has no tenant context; bypass the tenant scope to find the voter.
        $voter = Voter::withoutGlobalScope(\App\Scopes\TenantScope::class)->find($data['id']);

        if (! $voter) {
            return response()->json(['success' => false, 'message' => 'Votante no encontrado.'], 404);
        }

        $voter->update([
            'departamento_votacion' => $data['departamento_votacion'],
            'municipio_votacion' => $data['municipio_votacion'],
            'puesto_votacion' => $data['puesto_votacion'],
            'direccion_votacion' => $data['direccion_votacion'] ?? null,
            'mesa_votacion' => $data['mesa_votacion'] ?? null,
            'voting_place_id' => $votingPlace->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Información de registraduría actualizada correctamente.',
            'data' => $voter->fresh(),
        ]);
    }
}
