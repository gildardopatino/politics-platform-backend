<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SurveyController extends Controller
{
    /**
     * Display a listing of surveys
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
        $isActive = $request->input('is_active');

        $query = Survey::with(['createdBy:id,name', 'questions']);

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        $surveys = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $surveys->items(),
            'pagination' => [
                'total' => $surveys->total(),
                'per_page' => $surveys->perPage(),
                'current_page' => $surveys->currentPage(),
                'last_page' => $surveys->lastPage(),
                'from' => $surveys->firstItem(),
                'to' => $surveys->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created survey with questions
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'titulo' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'questions' => 'required|array|min:1',
            'questions.*.question_text' => 'required|string',
            'questions.*.question_type' => 'required|in:multiple_choice,yes_no,text,scale',
            'questions.*.options' => 'nullable|json',
            'questions.*.order' => 'nullable|integer',
            'questions.*.is_required' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $survey = Survey::create([
                'tenant_id' => auth()->user()->tenant_id,
                'titulo' => $request->titulo,
                'descripcion' => $request->descripcion,
                'is_active' => $request->is_active ?? true,
                'starts_at' => $request->starts_at,
                'ends_at' => $request->ends_at,
                'created_by' => auth()->id(),
            ]);

            // Crear preguntas
            foreach ($request->questions as $index => $questionData) {
                SurveyQuestion::create([
                    'survey_id' => $survey->id,
                    'question_text' => $questionData['question_text'],
                    'question_type' => $questionData['question_type'],
                    'options' => isset($questionData['options']) ? json_encode($questionData['options']) : null,
                    'order' => $questionData['order'] ?? $index + 1,
                    'is_required' => $questionData['is_required'] ?? false,
                ]);
            }

            DB::commit();

            $survey->load('questions');

            return response()->json([
                'success' => true,
                'message' => 'Encuesta creada exitosamente',
                'data' => $survey,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la encuesta',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified survey
     */
    public function show(Survey $survey): JsonResponse
    {
        $survey->load(['questions', 'createdBy:id,name']);

        return response()->json([
            'success' => true,
            'data' => $survey,
        ]);
    }

    /**
     * Update the specified survey
     */
    public function update(Request $request, Survey $survey): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'titulo' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'questions' => 'nullable|array',
            'questions.*.id' => 'nullable|exists:survey_questions,id',
            'questions.*.question_text' => 'required|string',
            'questions.*.question_type' => 'required|in:multiple_choice,yes_no,text,scale',
            'questions.*.options' => 'nullable',
            'questions.*.order' => 'nullable|integer',
            'questions.*.is_required' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $survey->update([
                'titulo' => $request->titulo,
                'descripcion' => $request->descripcion,
                'is_active' => $request->is_active ?? $survey->is_active,
                'starts_at' => $request->starts_at,
                'ends_at' => $request->ends_at,
            ]);

            // Si se enviaron preguntas, actualizar/crear
            if ($request->has('questions')) {
                $questionIds = [];

                foreach ($request->questions as $index => $questionData) {
                    if (isset($questionData['id'])) {
                        // Actualizar pregunta existente
                        $question = SurveyQuestion::find($questionData['id']);
                        if ($question && $question->survey_id == $survey->id) {
                            $question->update([
                                'question_text' => $questionData['question_text'],
                                'question_type' => $questionData['question_type'],
                                'options' => isset($questionData['options']) ? json_encode($questionData['options']) : null,
                                'order' => $questionData['order'] ?? $index + 1,
                                'is_required' => $questionData['is_required'] ?? false,
                            ]);
                            $questionIds[] = $question->id;
                        }
                    } else {
                        // Crear nueva pregunta
                        $newQuestion = SurveyQuestion::create([
                            'survey_id' => $survey->id,
                            'question_text' => $questionData['question_text'],
                            'question_type' => $questionData['question_type'],
                            'options' => isset($questionData['options']) ? json_encode($questionData['options']) : null,
                            'order' => $questionData['order'] ?? $index + 1,
                            'is_required' => $questionData['is_required'] ?? false,
                        ]);
                        $questionIds[] = $newQuestion->id;
                    }
                }

                // Eliminar preguntas que no están en la lista
                SurveyQuestion::where('survey_id', $survey->id)
                    ->whereNotIn('id', $questionIds)
                    ->delete();
            }

            DB::commit();

            $survey->load('questions');

            return response()->json([
                'success' => true,
                'message' => 'Encuesta actualizada exitosamente',
                'data' => $survey,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la encuesta',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified survey
     */
    public function destroy(Survey $survey): JsonResponse
    {
        $survey->delete();

        return response()->json([
            'success' => true,
            'message' => 'Encuesta eliminada exitosamente',
        ]);
    }

    /**
     * Activate survey
     */
    public function activate(Survey $survey): JsonResponse
    {
        $survey->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Encuesta activada exitosamente',
            'data' => $survey,
        ]);
    }

    /**
     * Deactivate survey
     */
    public function deactivate(Survey $survey): JsonResponse
    {
        $survey->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Encuesta desactivada exitosamente',
            'data' => $survey,
        ]);
    }

    /**
     * Clone survey
     */
    public function cloneSurvey(Request $request, Survey $survey): JsonResponse
    {
        try {
            DB::beginTransaction();

            $newSurvey = Survey::create([
                'tenant_id' => $survey->tenant_id,
                'titulo' => $request->input('titulo', $survey->titulo . ' - Copia'),
                'descripcion' => $survey->descripcion,
                'is_active' => false, // Nueva encuesta inactiva por defecto
                'starts_at' => null,
                'ends_at' => null,
                'created_by' => auth()->id(),
            ]);

            // Copiar preguntas
            foreach ($survey->questions as $question) {
                SurveyQuestion::create([
                    'survey_id' => $newSurvey->id,
                    'question_text' => $question->question_text,
                    'question_type' => $question->question_type,
                    'options' => $question->options,
                    'order' => $question->order,
                    'is_required' => $question->is_required,
                ]);
            }

            DB::commit();

            $newSurvey->load('questions');

            return response()->json([
                'success' => true,
                'message' => 'Encuesta clonada exitosamente',
                'data' => $newSurvey,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al clonar la encuesta',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get active surveys
     */
    public function active(): JsonResponse
    {
        $surveys = Survey::current()
            ->with('questions')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $surveys,
        ]);
    }
}
