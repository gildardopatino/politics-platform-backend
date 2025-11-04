<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Meeting\CheckInRequest;
use App\Http\Requests\Api\V1\Meeting\StoreMeetingRequest;
use App\Http\Requests\Api\V1\Meeting\UpdateMeetingRequest;
use App\Http\Resources\Api\V1\MeetingResource;
use App\Jobs\Meetings\GenerateQRCodeJob;
use App\Models\Meeting;
use App\Services\AttendeeHierarchyService;
use App\Services\QRCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\QueryBuilder;

class MeetingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $meetings = QueryBuilder::for(Meeting::class)
            ->allowedFilters(['title', 'status', 'department_id', 'municipality_id', 'commune_id', 'barrio_id'])
            ->allowedIncludes(['planner', 'template', 'attendees', 'commitments', 'department', 'municipality', 'commune', 'barrio', 'corregimiento', 'vereda'])
            ->allowedSorts(['starts_at', 'created_at', 'title', 'status'])
            ->with(['planner', 'department', 'municipality', 'commune', 'barrio', 'template'])
            ->withCount(['attendees', 'commitments'])
            ->paginate(request('per_page', 15));

        return response()->json([
            'data' => MeetingResource::collection($meetings->items()),
            'meta' => [
                'total' => $meetings->total(),
                'current_page' => $meetings->currentPage(),
                'last_page' => $meetings->lastPage(),
                'per_page' => $meetings->perPage(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMeetingRequest $request, QRCodeService $qrCodeService, AttendeeHierarchyService $hierarchyService): JsonResponse
    {
        try {
            /** @var \App\Models\User $user */
            $user = $request->user();
            
            Log::info('Creating meeting', [
                'tenant_id' => $user->tenant_id,
                'validated_data' => $request->validated()
            ]);
            
            $meeting = Meeting::create([
                'tenant_id' => $user->tenant_id,
                ...$request->validated()
            ]);

            Log::info('Meeting created', ['meeting_id' => $meeting->id]);

            // Generate QR code synchronously
            $qrData = $qrCodeService->generateForMeeting(
                $meeting->id,
                $meeting->tenant->slug
            );

            $meeting->update(['qr_code' => $qrData['code']]);
            $meeting->qr_data = $qrData; // Attach QR data temporarily for response

            return response()->json([
                'data' => new MeetingResource($meeting->load(['planner', 'department', 'municipality', 'commune', 'barrio', 'template'])),
                'message' => 'Meeting created successfully'
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating meeting', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Error creating meeting: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Meeting $meeting): JsonResponse
    {
        $meeting->load(['planner', 'template', 'attendees', 'commitments', 'department', 'municipality', 'commune', 'barrio', 'corregimiento', 'vereda']);
        
        return response()->json([
            'data' => new MeetingResource($meeting)
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMeetingRequest $request, Meeting $meeting): JsonResponse
    {
        $meeting->update($request->validated());

        return response()->json([
            'data' => new MeetingResource($meeting->load(['planner', 'department', 'municipality', 'commune', 'barrio', 'template'])),
            'message' => 'Meeting updated successfully'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Meeting $meeting): JsonResponse
    {
        $meeting->delete();

        return response()->json([
            'message' => 'Meeting deleted successfully'
        ]);
    }

    /**
     * Complete a meeting
     */
    public function complete(Meeting $meeting, AttendeeHierarchyService $hierarchyService): JsonResponse
    {
        $meeting->update([
            'status' => 'completed',
            'ends_at' => now()
        ]);

        // Procesar jerarquías de asistentes si está configurado
        $hierarchyService->processHierarchyForMeeting($meeting);

        return response()->json([
            'data' => new MeetingResource($meeting->load(['planner', 'department', 'municipality', 'commune', 'barrio', 'template'])),
            'message' => 'Meeting marked as completed'
        ]);
    }

    /**
     * Cancel a meeting
     */
    public function cancel(Meeting $meeting): JsonResponse
    {
        $meeting->update(['status' => 'cancelled']);

        return response()->json([
            'data' => new MeetingResource($meeting->load(['planner', 'department', 'municipality', 'commune', 'barrio', 'template'])),
            'message' => 'Meeting cancelled'
        ]);
    }

    /**
     * Get QR code for meeting
     */
    public function getQRCode(Meeting $meeting, QRCodeService $qrCodeService): JsonResponse
    {
        if (!$meeting->qr_code) {
            return response()->json([
                'message' => 'QR code not generated yet'
            ], 404);
        }

        $qrData = $qrCodeService->getQRCodeBase64($meeting->qr_code);
        $qrPath = $qrCodeService->getQRCodePath($meeting->qr_code, $meeting->tenant->slug);

        return response()->json([
            'qr_code' => $meeting->qr_code,
            'qr_url' => $qrPath ? asset("storage/{$qrPath}") : null,
            'check_in_url' => $qrData['url'],
            'svg' => $qrData['svg'],
            'svg_base64' => $qrData['svg_base64'],
        ]);
    }

    /**
     * Show meeting by QR code (public)
     */
    public function showByQR(string $qrCode): JsonResponse
    {
        $meeting = Meeting::where('qr_code', $qrCode)->firstOrFail();
        $meeting->load(['planner', 'department', 'municipality', 'commune', 'barrio', 'template']);

        return response()->json([
            'data' => new MeetingResource($meeting)
        ]);
    }

    /**
     * Get public meeting information for check-in page
     * Returns simplified meeting data for frontend display
     */
    public function getPublicInfo(string $qrCode): JsonResponse
    {
        $meeting = Meeting::where('qr_code', $qrCode)
            ->with([
                'planner:id,name,email,phone',
                'department:id,nombre',
                'municipality:id,nombre', 
                'commune:id,nombre',
                'barrio:id,nombre',
                'template:id,nombre'
            ])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $meeting->id,
                'titulo' => $meeting->titulo,
                'descripcion' => $meeting->descripcion,
                'objetivo' => $meeting->objetivo,
                'starts_at' => $meeting->starts_at,
                'ends_at' => $meeting->ends_at,
                'status' => $meeting->status,
                'lugar_tipo' => $meeting->lugar_tipo,
                'lugar_nombre' => $meeting->lugar_nombre,
                'lugar_direccion' => $meeting->lugar_direccion,
                'lugar_url' => $meeting->lugar_url,
                'planner' => $meeting->planner ? [
                    'id' => $meeting->planner->id,
                    'name' => $meeting->planner->name,
                    'email' => $meeting->planner->email,
                    'phone' => $meeting->planner->phone,
                ] : null,
                'location' => [
                    'department' => $meeting->department?->nombre,
                    'municipality' => $meeting->municipality?->nombre,
                    'commune' => $meeting->commune?->nombre,
                    'barrio' => $meeting->barrio?->nombre,
                ],
                'template' => $meeting->template ? [
                    'id' => $meeting->template->id,
                    'nombre' => $meeting->template->nombre,
                ] : null,
                'attendees_count' => $meeting->attendees()->count(),
                'checked_in_count' => $meeting->attendees()->where('checked_in', true)->count(),
            ]
        ]);
    }

    /**
     * Check in to meeting via QR code (public)
     */
    public function checkIn(string $qrCode, CheckInRequest $request): JsonResponse
    {
        $meeting = Meeting::where('qr_code', $qrCode)->firstOrFail();

        $attendee = $meeting->attendees()->create([
            ...$request->validated(),
            'created_by' => $request->user()?->id,
            'checked_in' => true,
            'checked_in_at' => now()
        ]);

        return response()->json([
            'data' => $attendee,
            'message' => 'Check-in successful'
        ], 201);
    }

    /**
     * Get meetings hierarchy tree based on attendees requesting meetings
     * Returns a tree structure of people and their requested meetings
     */
    public function getHierarchyTree(Request $request): JsonResponse
    {
        // Obtener el parámetro include_attendees (por defecto false)
        $includeAttendees = $request->boolean('include_attendees', false);

        // Obtener todas las reuniones con sus relaciones
        $meetings = Meeting::with([
            'planner:id,name,cedula',
            'attendees:id,meeting_id,cedula,nombres,apellidos,telefono,email',
            'barrio:id,nombre',
            'commune:id,nombre'
        ])->get();

        // Construir un mapa de cédulas a reuniones solicitadas
        $cedulaToMeetings = [];
        foreach ($meetings as $meeting) {
            if ($meeting->assigned_to_cedula) {
                if (!isset($cedulaToMeetings[$meeting->assigned_to_cedula])) {
                    $cedulaToMeetings[$meeting->assigned_to_cedula] = [];
                }
                $cedulaToMeetings[$meeting->assigned_to_cedula][] = $meeting;
            }
        }

        // Función recursiva para construir el árbol
        $buildTree = function($cedula, $depth = 0) use (&$buildTree, $cedulaToMeetings, $meetings, $includeAttendees) {
            if ($depth > 10) return []; // Prevenir recursión infinita

            $result = [];

            // Encontrar reuniones solicitadas por esta cédula
            if (isset($cedulaToMeetings[$cedula])) {
                foreach ($cedulaToMeetings[$cedula] as $meeting) {
                    // Buscar información del solicitante en los asistentes
                    $requester = null;
                    foreach ($meetings as $m) {
                        $attendee = $m->attendees->firstWhere('cedula', $cedula);
                        if ($attendee) {
                            $requester = [
                                'cedula' => $attendee->cedula,
                                'nombres' => $attendee->nombres,
                                'apellidos' => $attendee->apellidos,
                                'full_name' => $attendee->nombres . ' ' . $attendee->apellidos,
                                'telefono' => $attendee->telefono,
                                'email' => $attendee->email,
                            ];
                            break;
                        }
                    }

                    $meetingData = [
                        'meeting' => [
                            'id' => $meeting->id,
                            'titulo' => $meeting->title,
                            'descripcion' => $meeting->description,
                            'starts_at' => $meeting->starts_at,
                            'status' => $meeting->status,
                            'lugar_nombre' => $meeting->lugar_nombre,
                            'location' => [
                                'barrio' => $meeting->barrio?->nombre,
                                'comuna' => $meeting->commune?->nombre,
                            ],
                            'attendees_count' => $meeting->attendees->count(),
                        ],
                        'requester' => $requester,
                        'children' => []
                    ];

                    // Agregar lista de asistentes si el flag está activado
                    if ($includeAttendees) {
                        $meetingData['meeting']['attendees'] = $meeting->attendees->map(function($attendee) {
                            return [
                                'id' => $attendee->id,
                                'cedula' => $attendee->cedula,
                                'nombres' => $attendee->nombres,
                                'apellidos' => $attendee->apellidos,
                                'full_name' => $attendee->nombres . ' ' . $attendee->apellidos,
                                'telefono' => $attendee->telefono,
                                'email' => $attendee->email,
                            ];
                        })->toArray();
                    }

                    // Buscar reuniones solicitadas por los asistentes de esta reunión
                    foreach ($meeting->attendees as $attendee) {
                        $childrenMeetings = $buildTree($attendee->cedula, $depth + 1);
                        if (!empty($childrenMeetings)) {
                            $meetingData['children'] = array_merge(
                                $meetingData['children'], 
                                $childrenMeetings
                            );
                        }
                    }

                    $result[] = $meetingData;
                }
            }

            return $result;
        };

        // Encontrar reuniones raíz (las que no tienen assigned_to_cedula o fueron creadas por el planner)
        $rootMeetings = Meeting::whereNull('assigned_to_cedula')
            ->orWhere('assigned_to_cedula', '')
            ->with([
                'planner:id,name,cedula',
                'attendees:id,meeting_id,cedula,nombres,apellidos,telefono,email',
                'barrio:id,nombre',
                'commune:id,nombre'
            ])
            ->get();

        $tree = [];
        foreach ($rootMeetings as $meeting) {
            $meetingNode = [
                'meeting' => [
                    'id' => $meeting->id,
                    'titulo' => $meeting->title,
                    'descripcion' => $meeting->description,
                    'starts_at' => $meeting->starts_at,
                    'status' => $meeting->status,
                    'lugar_nombre' => $meeting->lugar_nombre,
                    'location' => [
                        'barrio' => $meeting->barrio?->nombre,
                        'comuna' => $meeting->commune?->nombre,
                    ],
                    'attendees_count' => $meeting->attendees->count(),
                ],
                'requester' => $meeting->planner ? [
                    'cedula' => $meeting->planner->cedula,
                    'nombres' => $meeting->planner->name,
                    'apellidos' => '',
                    'full_name' => $meeting->planner->name,
                    'type' => 'planner'
                ] : null,
                'children' => []
            ];

            // Agregar lista de asistentes si el flag está activado
            if ($includeAttendees) {
                $meetingNode['meeting']['attendees'] = $meeting->attendees->map(function($attendee) {
                    return [
                        'id' => $attendee->id,
                        'cedula' => $attendee->cedula,
                        'nombres' => $attendee->nombres,
                        'apellidos' => $attendee->apellidos,
                        'full_name' => $attendee->nombres . ' ' . $attendee->apellidos,
                        'telefono' => $attendee->telefono,
                        'email' => $attendee->email,
                    ];
                })->toArray();
            }

            // Buscar reuniones solicitadas por los asistentes
            foreach ($meeting->attendees as $attendee) {
                $childrenMeetings = $buildTree($attendee->cedula);
                if (!empty($childrenMeetings)) {
                    $meetingNode['children'] = array_merge(
                        $meetingNode['children'], 
                        $childrenMeetings
                    );
                }
            }

            $tree[] = $meetingNode;
        }

        return response()->json([
            'success' => true,
            'data' => $tree,
            'meta' => [
                'total_meetings' => $meetings->count(),
                'root_meetings' => $rootMeetings->count(),
                'include_attendees' => $includeAttendees,
            ]
        ]);
    }
}
