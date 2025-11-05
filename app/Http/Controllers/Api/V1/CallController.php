<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\SurveyResponse;
use App\Models\Voter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CallController extends Controller
{
    /**
     * Display a listing of calls
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
        $status = $request->input('status');
        $surveyId = $request->input('survey_id');

        $query = Call::with([
            'voter:id,cedula,nombres,apellidos,telefono',
            'survey:id,titulo',
            'user:id,name'
        ]);

        if ($status) {
            $query->byStatus($status);
        }

        if ($surveyId) {
            $query->where('survey_id', $surveyId);
        }

        $calls = $query->latest('call_date')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $calls->items(),
            'pagination' => [
                'total' => $calls->total(),
                'per_page' => $calls->perPage(),
                'current_page' => $calls->currentPage(),
                'last_page' => $calls->lastPage(),
                'from' => $calls->firstItem(),
                'to' => $calls->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created call with responses
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'voter_id' => 'required|exists:voters,id',
            'survey_id' => 'nullable|exists:surveys,id',
            'call_date' => 'required|date',
            'duration_seconds' => 'nullable|integer|min:0',
            'status' => 'required|in:completed,no_answer,busy,rejected,wrong_number,voicemail',
            'notes' => 'nullable|string',
            'responses' => 'nullable|array',
            'responses.*.survey_question_id' => 'required|exists:survey_questions,id',
            'responses.*.answer_text' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $call = Call::create([
                'tenant_id' => auth()->user()->tenant_id,
                'voter_id' => $request->voter_id,
                'survey_id' => $request->survey_id,
                'user_id' => auth()->id(),
                'call_date' => $request->call_date,
                'duration_seconds' => $request->duration_seconds,
                'status' => $request->status,
                'notes' => $request->notes,
            ]);

            // Guardar respuestas si las hay
            if ($request->has('responses') && is_array($request->responses)) {
                foreach ($request->responses as $response) {
                    SurveyResponse::create([
                        'call_id' => $call->id,
                        'survey_question_id' => $response['survey_question_id'],
                        'voter_id' => $request->voter_id,
                        'answer_text' => $response['answer_text'],
                    ]);
                }
            }

            DB::commit();

            $call->load(['voter', 'survey', 'user', 'responses.surveyQuestion']);

            return response()->json([
                'success' => true,
                'message' => 'Llamada registrada exitosamente',
                'data' => $call,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar la llamada',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified call
     */
    public function show(Call $call): JsonResponse
    {
        $call->load([
            'voter:id,cedula,nombres,apellidos,telefono,email',
            'survey:id,titulo',
            'user:id,name',
            'responses.surveyQuestion'
        ]);

        return response()->json([
            'success' => true,
            'data' => $call,
        ]);
    }

    /**
     * Update the specified call
     */
    public function update(Request $request, Call $call): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'voter_id' => 'required|exists:voters,id',
            'survey_id' => 'nullable|exists:surveys,id',
            'call_date' => 'required|date',
            'duration_seconds' => 'nullable|integer|min:0',
            'status' => 'required|in:completed,no_answer,busy,rejected,wrong_number,voicemail',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $call->update($validator->validated());
        $call->load(['voter', 'survey', 'user']);

        return response()->json([
            'success' => true,
            'message' => 'Llamada actualizada exitosamente',
            'data' => $call,
        ]);
    }

    /**
     * Remove the specified call
     */
    public function destroy(Call $call): JsonResponse
    {
        $call->delete();

        return response()->json([
            'success' => true,
            'message' => 'Llamada eliminada exitosamente',
        ]);
    }

    /**
     * Get calls by voter
     */
    public function byVoter(Voter $voter): JsonResponse
    {
        $calls = Call::byVoter($voter->id)
            ->with(['survey:id,titulo', 'user:id,name'])
            ->latest('call_date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $calls,
        ]);
    }

    /**
     * Get call statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $surveyId = $request->input('survey_id');

        $query = Call::query();

        if ($dateFrom) {
            $query->where('call_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('call_date', '<=', $dateTo);
        }

        if ($surveyId) {
            $query->where('survey_id', $surveyId);
        }

        $totalCalls = $query->count();
        
        $byStatus = Call::selectRaw('status, COUNT(*) as count')
            ->when($dateFrom, fn($q) => $q->where('call_date', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->where('call_date', '<=', $dateTo))
            ->when($surveyId, fn($q) => $q->where('survey_id', $surveyId))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $avgDuration = $query->whereNotNull('duration_seconds')->avg('duration_seconds');
        $totalDuration = $query->sum('duration_seconds');
        $uniqueVoters = $query->distinct('voter_id')->count('voter_id');
        
        $completedCalls = $byStatus['completed'] ?? 0;
        $completionRate = $totalCalls > 0 ? round(($completedCalls / $totalCalls) * 100, 2) : 0;

        $bySurvey = Call::selectRaw('survey_id, COUNT(*) as total_calls, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_calls', ['completed'])
            ->whereNotNull('survey_id')
            ->when($dateFrom, fn($q) => $q->where('call_date', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->where('call_date', '<=', $dateTo))
            ->groupBy('survey_id')
            ->with('survey:id,titulo')
            ->get()
            ->map(function ($item) {
                return [
                    'survey_id' => $item->survey_id,
                    'titulo' => $item->survey->titulo ?? 'Sin encuesta',
                    'total_calls' => $item->total_calls,
                    'completed_calls' => $item->completed_calls,
                ];
            });

        $byUser = Call::selectRaw('user_id, COUNT(*) as total_calls, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_calls', ['completed'])
            ->when($dateFrom, fn($q) => $q->where('call_date', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->where('call_date', '<=', $dateTo))
            ->groupBy('user_id')
            ->with('user:id,name')
            ->get()
            ->map(function ($item) {
                return [
                    'user_id' => $item->user_id,
                    'name' => $item->user->name ?? 'Desconocido',
                    'total_calls' => $item->total_calls,
                    'completed_calls' => $item->completed_calls,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'total_calls' => $totalCalls,
                'by_status' => [
                    'completed' => $byStatus['completed'] ?? 0,
                    'no_answer' => $byStatus['no_answer'] ?? 0,
                    'busy' => $byStatus['busy'] ?? 0,
                    'rejected' => $byStatus['rejected'] ?? 0,
                    'wrong_number' => $byStatus['wrong_number'] ?? 0,
                    'voicemail' => $byStatus['voicemail'] ?? 0,
                ],
                'average_duration' => round($avgDuration ?? 0),
                'total_duration' => $totalDuration,
                'unique_voters_contacted' => $uniqueVoters,
                'completion_rate' => $completionRate,
                'by_survey' => $bySurvey,
                'by_user' => $byUser,
            ],
        ]);
    }
}
