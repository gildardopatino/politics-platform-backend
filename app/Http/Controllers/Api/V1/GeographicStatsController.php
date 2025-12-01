<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Barrio;
use App\Models\Commune;
use App\Models\Corregimiento;
use App\Models\Vereda;
use App\Models\Municipality;
use App\Models\Meeting;
use App\Models\Commitment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GeographicStatsController extends Controller
{
    /**
     * Obtener estadísticas geográficas según el tipo de dato y ubicación
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $request->validate([
            'type' => 'required|in:compromisos,reuniones,electores',
            'geographic_type' => 'required|in:municipio,comuna,barrio,corregimiento,vereda',
        ], [
            'type.required' => 'El tipo de estadística es obligatorio.',
            'type.in' => 'El tipo debe ser: compromisos, reuniones o electores.',
            'geographic_type.required' => 'El tipo geográfico es obligatorio.',
            'geographic_type.in' => 'El tipo geográfico debe ser: municipio, comuna, barrio, corregimiento o vereda.',
        ]);

        $type = $request->input('type');
        $geographicType = $request->input('geographic_type');

        if ($type === 'compromisos') {
            return $this->getCommitmentsStats($geographicType);
        } elseif ($type === 'reuniones') {
            return $this->getMeetingsStats($geographicType);
        } elseif ($type === 'electores') {
            return $this->getVotersStats($geographicType);
        }
    }

    /**
     * Obtener estadísticas de compromisos por ubicación geográfica
     * 
     * @param string $geographicType
     * @return \Illuminate\Http\JsonResponse
     */
    private function getCommitmentsStats($geographicType)
    {
        $json = [];

        switch ($geographicType) {
            case 'municipio':
                $locations = Municipality::whereNotNull('path')
                    ->with(['meetings' => function($query) {
                        $query->whereNull('deleted_at')
                              ->with(['commitments' => function($q) {
                                  $q->whereNull('deleted_at')
                                    ->with(['assignedUser:id,name', 'priority:id,name']);
                              }]);
                    }])
                    ->get();
                break;

            case 'comuna':
                $locations = Commune::whereNotNull('path')
                    ->with(['meetings' => function($query) {
                        $query->whereNull('deleted_at')
                              ->with(['commitments' => function($q) {
                                  $q->whereNull('deleted_at')
                                    ->with(['assignedUser:id,name', 'priority:id,name']);
                              }]);
                    }])
                    ->get();
                break;

            case 'barrio':
                $locations = Barrio::whereNotNull('path')
                    ->with(['meetings' => function($query) {
                        $query->whereNull('deleted_at')
                              ->with(['commitments' => function($q) {
                                  $q->whereNull('deleted_at')
                                    ->with(['assignedUser:id,name', 'priority:id,name']);
                              }]);
                    }])
                    ->get();
                break;

            case 'corregimiento':
                $locations = Corregimiento::whereNotNull('path')
                    ->with(['meetings' => function($query) {
                        $query->whereNull('deleted_at')
                              ->with(['commitments' => function($q) {
                                  $q->whereNull('deleted_at')
                                    ->with(['assignedUser:id,name', 'priority:id,name']);
                              }]);
                    }])
                    ->get();
                break;

            case 'vereda':
                $locations = Vereda::whereNotNull('path')
                    ->with(['meetings' => function($query) {
                        $query->whereNull('deleted_at')
                              ->with(['commitments' => function($q) {
                                  $q->whereNull('deleted_at')
                                    ->with(['assignedUser:id,name', 'priority:id,name']);
                              }]);
                    }])
                    ->get();
                break;
        }

        $totalCommitments = 0;

        foreach ($locations as $location) {
            $commitments = [];
            $count = 0;

            foreach ($location->meetings as $meeting) {
                foreach ($meeting->commitments as $commitment) {
                    $commitments[] = [
                        'id' => $commitment->id,
                        'description' => $commitment->description,
                        'status' => $commitment->status,
                        'due_date' => $commitment->due_date,
                        'assigned_user' => $commitment->assignedUser ? [
                            'id' => $commitment->assignedUser->id,
                            'name' => $commitment->assignedUser->name
                        ] : null,
                        'priority' => $commitment->priority ? [
                            'id' => $commitment->priority->id,
                            'name' => $commitment->priority->name
                        ] : null,
                        'meeting_id' => $meeting->id,
                        'meeting_title' => $meeting->title,
                    ];
                    $count++;
                }
            }

            $totalCommitments += $count;

            // Azul pastel si tiene compromisos, rojo si no tiene
            $color = $count > 0 ? '#13db2dff' : '#F54927';

            $json[] = [
                'id' => "id{$location->id}",
                'name' => $location->nombre,
                'path' => $location->path,
                'value' => $count,
                'color' => $color,
                'commitments' => $commitments
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $json,
            'meta' => [
                'type' => 'compromisos',
                'geographic_type' => $geographicType,
                'total_locations' => count($json),
                'total_count' => $totalCommitments
            ]
        ]);
    }

    /**
     * Obtener estadísticas de reuniones por ubicación geográfica
     * 
     * @param string $geographicType
     * @return \Illuminate\Http\JsonResponse
     */
    private function getMeetingsStats($geographicType)
    {
        $json = [];

        switch ($geographicType) {
            case 'municipio':
                $locations = Municipality::whereNotNull('path')
                    ->with(['meetings' => function($query) {
                        $query->whereNull('deleted_at')
                              ->with(['planner:id,name', 'attendees']);
                    }])
                    ->get();
                break;

            case 'comuna':
                $locations = Commune::whereNotNull('path')
                    ->with(['meetings' => function($query) {
                        $query->whereNull('deleted_at')
                              ->with(['planner:id,name', 'attendees']);
                    }])
                    ->get();
                break;

            case 'barrio':
                $locations = Barrio::whereNotNull('path')
                    ->with(['meetings' => function($query) {
                        $query->whereNull('deleted_at')
                              ->with(['planner:id,name', 'attendees']);
                    }])
                    ->get();
                break;

            case 'corregimiento':
                $locations = Corregimiento::whereNotNull('path')
                    ->with(['meetings' => function($query) {
                        $query->whereNull('deleted_at')
                              ->with(['planner:id,name', 'attendees']);
                    }])
                    ->get();
                break;

            case 'vereda':
                $locations = Vereda::whereNotNull('path')
                    ->with(['meetings' => function($query) {
                        $query->whereNull('deleted_at')
                              ->with(['planner:id,name', 'attendees']);
                    }])
                    ->get();
                break;
        }

        $totalMeetings = 0;

        foreach ($locations as $location) {
            $meetings = [];

            foreach ($location->meetings as $meeting) {
                $meetings[] = [
                    'id' => $meeting->id,
                    'title' => $meeting->title,
                    'description' => $meeting->description,
                    'status' => $meeting->status,
                    'starts_at' => $meeting->starts_at,
                    'ends_at' => $meeting->ends_at,
                    'lugar_nombre' => $meeting->lugar_nombre,
                    'planner' => $meeting->planner ? [
                        'id' => $meeting->planner->id,
                        'name' => $meeting->planner->name
                    ] : null,
                    'attendees_count' => $meeting->attendees->count(),
                ];
            }

            $meetingsCount = count($meetings);
            $totalMeetings += $meetingsCount;

            // Azul pastel si tiene reuniones, rojo si no tiene
            $color = $meetingsCount > 0 ? '#13db2dff' : '#F54927';

            $json[] = [
                'id' => "id{$location->id}",
                'name' => $location->nombre,
                'path' => $location->path,
                'value' => $meetingsCount,
                'color' => $color,
                'meetings' => $meetings
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $json,
            'meta' => [
                'type' => 'reuniones',
                'geographic_type' => $geographicType,
                'total_locations' => count($json),
                'total_count' => $totalMeetings
            ]
        ]);
    }

    /**
     * Obtener estadísticas de electores (voters) por ubicación geográfica
     * 
     * @param string $geographicType
     * @return \Illuminate\Http\JsonResponse
     */
    private function getVotersStats($geographicType)
    {
        $json = [];

        switch ($geographicType) {
            case 'municipio':
                $locations = Municipality::whereNotNull('path')->get();
                
                foreach ($locations as $location) {
                    // Contar voters en barrios de este municipio
                    $votersCount = \App\Models\Voter::whereHas('barrio', function($q) use ($location) {
                        $q->where('municipality_id', $location->id);
                    })->count();

                    $voters = [];
                    if ($votersCount > 0) {
                        $voters = \App\Models\Voter::whereHas('barrio', function($q) use ($location) {
                                $q->where('municipality_id', $location->id);
                            })
                            ->get()
                            ->map(function($voter) {
                                return [
                                    'cedula' => $voter->cedula,
                                    'nombres' => $voter->nombres,
                                    'apellidos' => $voter->apellidos,
                                ];
                            })
                            ->toArray();
                    }

                    $color = $votersCount > 0 ? '#13db2dff' : '#F54927';

                    $json[] = [
                        'id' => "id{$location->id}",
                        'name' => $location->nombre,
                        'path' => $location->path,
                        'value' => $votersCount,
                        'color' => $color,
                        'voters' => $voters
                    ];
                }
                break;

            case 'comuna':
                $locations = Commune::whereNotNull('path')->get();
                
                foreach ($locations as $location) {
                    // Contar voters en barrios de esta comuna
                    $votersCount = \App\Models\Voter::whereHas('barrio', function($q) use ($location) {
                        $q->where('commune_id', $location->id);
                    })->count();

                    $voters = [];
                    if ($votersCount > 0) {
                        $voters = \App\Models\Voter::whereHas('barrio', function($q) use ($location) {
                                $q->where('commune_id', $location->id);
                            })
                            ->get()
                            ->map(function($voter) {
                                return [
                                    'cedula' => $voter->cedula,
                                    'nombres' => $voter->nombres,
                                    'apellidos' => $voter->apellidos,
                                ];
                            })
                            ->toArray();
                    }

                    $color = $votersCount > 0 ? '#13db2dff' : '#F54927';

                    $json[] = [
                        'id' => "id{$location->id}",
                        'name' => $location->nombre,
                        'path' => $location->path,
                        'value' => $votersCount,
                        'color' => $color,
                        'voters' => $voters
                    ];
                }
                break;

            case 'barrio':
                $locations = Barrio::whereNotNull('path')->get();
                
                foreach ($locations as $location) {
                    // Contar voters directamente en este barrio
                    $votersCount = \App\Models\Voter::where('barrio_id', $location->id)->count();

                    $voters = [];
                    if ($votersCount > 0) {
                        $voters = \App\Models\Voter::where('barrio_id', $location->id)
                            ->get()
                            ->map(function($voter) {
                                return [
                                    'cedula' => $voter->cedula,
                                    'nombres' => $voter->nombres,
                                    'apellidos' => $voter->apellidos,
                                ];
                            })
                            ->toArray();
                    }

                    $color = $votersCount > 0 ? '#13db2dff' : '#F54927';

                    $json[] = [
                        'id' => "id{$location->id}",
                        'name' => $location->nombre,
                        'path' => $location->path,
                        'value' => $votersCount,
                        'color' => $color,
                        'voters' => $voters
                    ];
                }
                break;

            case 'corregimiento':
                $locations = Corregimiento::whereNotNull('path')->get();
                
                foreach ($locations as $location) {
                    // Contar voters directamente en este corregimiento
                    $votersCount = \App\Models\Voter::where('corregimiento_id', $location->id)->count();

                    $voters = [];
                    if ($votersCount > 0) {
                        $voters = \App\Models\Voter::where('corregimiento_id', $location->id)
                            ->get()
                            ->map(function($voter) {
                                return [
                                    'cedula' => $voter->cedula,
                                    'nombres' => $voter->nombres,
                                    'apellidos' => $voter->apellidos,
                                ];
                            })
                            ->toArray();
                    }

                    $color = $votersCount > 0 ? '#13db2dff' : '#F54927';

                    $json[] = [
                        'id' => "id{$location->id}",
                        'name' => $location->nombre,
                        'path' => $location->path,
                        'value' => $votersCount,
                        'color' => $color,
                        'voters' => $voters
                    ];
                }
                break;

            case 'vereda':
                $locations = Vereda::whereNotNull('path')->get();
                
                foreach ($locations as $location) {
                    // Contar voters directamente en esta vereda
                    $votersCount = \App\Models\Voter::where('vereda_id', $location->id)->count();

                    $voters = [];
                    if ($votersCount > 0) {
                        $voters = \App\Models\Voter::where('vereda_id', $location->id)
                            ->get()
                            ->map(function($voter) {
                                return [
                                    'cedula' => $voter->cedula,
                                    'nombres' => $voter->nombres,
                                    'apellidos' => $voter->apellidos,
                                ];
                            })
                            ->toArray();
                    }

                    $color = $votersCount > 0 ? '#13db2dff' : '#F54927';

                    $json[] = [
                        'id' => "id{$location->id}",
                        'name' => $location->nombre,
                        'path' => $location->path,
                        'value' => $votersCount,
                        'color' => $color,
                        'voters' => $voters
                    ];
                }
                break;
        }

        $totalVoters = collect($json)->sum('value');

        return response()->json([
            'success' => true,
            'data' => $json,
            'meta' => [
                'type' => 'electores',
                'geographic_type' => $geographicType,
                'total_locations' => count($json),
                'total_count' => $totalVoters
            ]
        ]);
    }
}
