<?php

namespace App\Http\Requests\Api\V1\Meeting;

use App\Services\AttendeeHierarchyService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreMeetingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422)
        );
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $tenant = app('tenant');
            
            if ($tenant && $tenant->require_hierarchy_config) {
                $hierarchyService = app(AttendeeHierarchyService::class);
                
                if (!$hierarchyService->validateHierarchyConfigRequired($tenant)) {
                    $validator->errors()->add(
                        'hierarchy_config', 
                        'Debe configurar las opciones de jerarquía de asistentes antes de crear reuniones.'
                    );
                }
            }
        });
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'template_id' => 'nullable|exists:meeting_templates,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'starts_at' => 'required|date',
            'planner_user_id' => 'required|exists:users,id',
            'assigned_to_cedula' => 'nullable|string|max:20',
            'lugar_nombre' => 'nullable|string',
            'department_id' => 'nullable|exists:departments,id',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'commune_id' => 'nullable|exists:communes,id',
            'barrio_id' => 'nullable|exists:barrios,id',
            'corregimiento_id' => 'nullable|exists:corregimientos,id',
            'vereda_id' => 'nullable|exists:veredas,id',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'metadata' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'template_id.exists' => 'La plantilla seleccionada no existe.',
            'title.required' => 'El título es obligatorio.',
            'title.max' => 'El título no puede exceder 255 caracteres.',
            'starts_at.required' => 'La fecha de inicio es obligatoria.',
            'starts_at.date' => 'La fecha de inicio no es válida.',
            'planner_user_id.required' => 'El organizador de la reunión es obligatorio.',
            'planner_user_id.exists' => 'El organizador seleccionado no existe.',
            'assigned_to_cedula.max' => 'La cédula no puede exceder 20 caracteres.',
            'department_id.exists' => 'El departamento seleccionado no existe.',
            'municipality_id.exists' => 'El municipio seleccionado no existe.',
            'commune_id.exists' => 'La comuna seleccionada no existe.',
            'barrio_id.exists' => 'El barrio seleccionado no existe.',
            'corregimiento_id.exists' => 'El corregimiento seleccionado no existe.',
            'vereda_id.exists' => 'La vereda seleccionada no existe.',
            'latitude.numeric' => 'La latitud debe ser un número.',
            'latitude.between' => 'La latitud debe estar entre -90 y 90.',
            'longitude.numeric' => 'La longitud debe ser un número.',
            'longitude.between' => 'La longitud debe estar entre -180 y 180.',
            'metadata.array' => 'Los metadatos deben ser un objeto válido.',
        ];
    }
}
