<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SurveyQuestionController extends Controller
{
    /**
     * Store a newly created question
     */
    public function store(Request $request, Survey $survey): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'question_text' => 'required|string',
            'question_type' => 'required|in:multiple_choice,yes_no,text,scale',
            'options' => 'nullable',
            'order' => 'nullable|integer',
            'is_required' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $question = SurveyQuestion::create([
            'survey_id' => $survey->id,
            'question_text' => $request->question_text,
            'question_type' => $request->question_type,
            'options' => $request->options ? json_encode($request->options) : null,
            'order' => $request->order ?? ($survey->questions()->max('order') + 1),
            'is_required' => $request->is_required ?? false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pregunta agregada exitosamente',
            'data' => $question,
        ], 201);
    }

    /**
     * Display the specified question
     */
    public function show(SurveyQuestion $question): JsonResponse
    {
        $question->load('survey:id,titulo');

        return response()->json([
            'success' => true,
            'data' => $question,
        ]);
    }

    /**
     * Update the specified question
     */
    public function update(Request $request, SurveyQuestion $question): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'question_text' => 'required|string',
            'question_type' => 'required|in:multiple_choice,yes_no,text,scale',
            'options' => 'nullable',
            'order' => 'nullable|integer',
            'is_required' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $question->update([
            'question_text' => $request->question_text,
            'question_type' => $request->question_type,
            'options' => $request->options ? json_encode($request->options) : null,
            'order' => $request->order ?? $question->order,
            'is_required' => $request->is_required ?? $question->is_required,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pregunta actualizada exitosamente',
            'data' => $question,
        ]);
    }

    /**
     * Remove the specified question
     */
    public function destroy(SurveyQuestion $question): JsonResponse
    {
        $question->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pregunta eliminada exitosamente',
        ]);
    }
}
